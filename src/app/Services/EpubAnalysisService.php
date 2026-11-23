<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;
use App\Repositories\ProjectRepository;
use App\Repositories\SourceAssetRepository;
use DOMDocument; use DOMElement; use DOMNode; use DOMXPath;
use InvalidArgumentException; use RuntimeException; use Throwable; use ZipArchive;
final class EpubAnalysisService
{
    public function analyze(string $projectId): array
    {
        $project=(new ProjectRepository())->find($projectId); if(!$project) throw new InvalidArgumentException('Project not found.');
        $asset=(new SourceAssetRepository())->findByProject($projectId); if(!$asset) throw new InvalidArgumentException('Upload an EPUB first.');
        $epub=rtrim((string)config('paths.projects'),'/').'/'.$projectId.'/'.$asset['stored_relpath'];
        if(!is_file($epub)) throw new RuntimeException('The immutable original.epub is missing.');
        $actual=hash_file('sha256',$epub); if($actual===false || !hash_equals($asset['sha256'],$actual)) throw new RuntimeException('Immutable-source checksum mismatch.');
        $zip=new ZipArchive(); if($zip->open($epub)!==true) throw new RuntimeException('Unable to open the stored EPUB.');
        try { $opfPath=$this->opfPath($this->read($zip,'META-INF/container.xml')); $book=$this->package($this->read($zip,$opfPath),$opfPath); $sections=$this->sections($zip,$book); } finally { $zip->close(); }
        $db=Database::connection(); $db->beginTransaction();
        try {
            // A fresh read of the EPUB replaces the source text, so the chapter structure built on the old text goes too.
            foreach(['logical_structure_revisions','logical_sections'] as $table) { $db->prepare("DELETE FROM {$table} WHERE project_id=:p")->execute(['p'=>$projectId]); }
            foreach(['import_warnings','source_blocks','source_sections','source_metadata'] as $table) { $db->prepare("DELETE FROM {$table} WHERE project_id=:p")->execute(['p'=>$projectId]); }
            $q=$db->prepare('INSERT INTO source_metadata(project_id,title,creator,language,identifier,package_path,package_version,analyzed_at) VALUES(:p,:t,:c,:l,:i,:path,:v,:at)');
            $q->execute(['p'=>$projectId,'t'=>$book['meta']['title'],'c'=>$book['meta']['creator'],'l'=>$book['meta']['language'],'i'=>$book['meta']['identifier'],'path'=>$opfPath,'v'=>$book['version'],'at'=>gmdate('Y-m-d H:i:s')]);
            $qs=$db->prepare('INSERT INTO source_sections(id,project_id,source_asset_id,spine_index,manifest_id,href,media_type,linear,title_hint,raw_sha256,word_count,block_count,warning_count,initial_recommendation) VALUES(:id,:p,:a,:n,:mid,:href,:media,:linear,:title,:sha,:words,:blocks,:warnings,:rec)');
            $qb=$db->prepare('INSERT INTO source_blocks(id,project_id,source_section_id,block_index,block_type,source_text,source_sha256,dom_path) VALUES(:id,:p,:s,:n,:type,:text,:sha,:path)');
            $qw=$db->prepare('INSERT INTO import_warnings(project_id,source_section_id,warning_code,message) VALUES(:p,:s,:c,:m)');
            foreach($sections as $section) {
                $qs->execute(['id'=>$section['id'],'p'=>$projectId,'a'=>$asset['id'],'n'=>$section['index'],'mid'=>$section['manifest'],'href'=>$section['href'],'media'=>$section['media'],'linear'=>$section['linear'],'title'=>$section['title'],'sha'=>$section['sha'],'words'=>$section['words'],'blocks'=>count($section['blocks']),'warnings'=>count($section['warnings']),'rec'=>$section['recommendation']]);
                foreach($section['blocks'] as $index=>$block) $qb->execute(['id'=>$this->uuid(),'p'=>$projectId,'s'=>$section['id'],'n'=>$index,'type'=>$block['type'],'text'=>$block['text'],'sha'=>hash('sha256',$block['text']),'path'=>$block['path']]);
                foreach($section['warnings'] as $warning) $qw->execute(['p'=>$projectId,'s'=>$section['id'],'c'=>$warning['code'],'m'=>$warning['message']]);
            }
            $db->prepare("UPDATE projects SET status='SECTION_REVIEW',updated_at=CURRENT_TIMESTAMP WHERE id=:p")->execute(['p'=>$projectId]);
            $db->commit();
        } catch(Throwable $error) { if($db->inTransaction()) $db->rollBack(); throw $error; }
        $blocks=array_sum(array_map(static fn(array $s): int=>count($s['blocks']),$sections));
        $manifestPath=dirname($epub).'/manifest.json'; $manifest=is_file($manifestPath)?json_decode((string)file_get_contents($manifestPath),true):[]; $manifest=is_array($manifest)?$manifest:[];
        $manifest['analysis_status']='ANALYZED_PHASE_2B'; $manifest['analyzed_at']=gmdate('c'); $manifest['package_path']=$opfPath; $manifest['metadata']=$book['meta']; $manifest['spine_sections']=count($sections); $manifest['source_blocks']=$blocks;
        $tmp=$manifestPath.'.tmp'; if(file_put_contents($tmp,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX)===false || !rename($tmp,$manifestPath)) throw new RuntimeException('Analysis succeeded, but the source manifest could not be updated.');
        return ['sections'=>count($sections),'blocks'=>$blocks];
    }
    private function opfPath(string $xml): string { $doc=$this->xml($xml); $path=trim((string)(new DOMXPath($doc))->query('//*[local-name()="rootfile"]/@full-path')?->item(0)?->nodeValue); if($path===''||str_starts_with($path,'/')||preg_match('#(^|/)\.\.(/|$)#',$path)) throw new InvalidArgumentException('Invalid OPF package path.'); return $path; }
    private function package(string $xml,string $path): array { $doc=$this->xml($xml); $xp=new DOMXPath($doc); $items=[]; foreach($xp->query('//*[local-name()="manifest"]/*[local-name()="item"]')?:[] as $node) if($node instanceof DOMElement) $items[$node->getAttribute('id')]=['id'=>$node->getAttribute('id'),'href'=>$node->getAttribute('href'),'media'=>$node->getAttribute('media-type')]; $spine=[]; foreach($xp->query('//*[local-name()="spine"]/*[local-name()="itemref"]')?:[] as $node) if($node instanceof DOMElement) $spine[]=['id'=>$node->getAttribute('idref'),'linear'=>$node->getAttribute('linear')!=='no']; if(!$spine) throw new InvalidArgumentException('The EPUB spine is empty.'); $value=static fn(string $name): string=>trim((string)$xp->query('//*[local-name()="metadata"]/*[local-name()="'.$name.'"]')?->item(0)?->textContent); return ['version'=>$doc->documentElement?->getAttribute('version')?:null,'meta'=>['title'=>$value('title'),'creator'=>$value('creator'),'language'=>$value('language'),'identifier'=>$value('identifier')],'items'=>$items,'spine'=>$spine,'base'=>dirname($path)==='.'?'':dirname($path).'/']; }
    private function sections(ZipArchive $zip,array $book): array { $out=[]; foreach($book['spine'] as $index=>$ref) { $item=$book['items'][$ref['id']]??null; if(!$item) continue; $href=$this->resolve($book['base'],$item['href']); $warnings=[]; $raw=''; $parsed=['title'=>basename($href),'blocks'=>[]]; try{$raw=$this->read($zip,$href);$parsed=$this->xhtml($raw,$href,$book['meta']['title']);}catch(Throwable $error){$warnings[]=['code'=>'SECTION_PARSE_FAILED','message'=>$error->getMessage()];} if(!$parsed['blocks'])$warnings[]=['code'=>'NO_TEXT_BLOCKS','message'=>'No narratable text blocks were found.']; $words=0; foreach($parsed['blocks'] as $block)$words+=preg_match_all('/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u',$block['text']); $title=$parsed['title']?:basename($href); $review=!\App\Services\SectionAdvisor::keep((string)$title,(string)$href,array_column($parsed['blocks'],'text'),(bool)$ref['linear']); $out[]=['id'=>$this->uuid(),'index'=>$index,'manifest'=>$item['id'],'href'=>$href,'media'=>$item['media'],'linear'=>$ref['linear']?1:0,'title'=>$title,'sha'=>hash('sha256',$raw),'words'=>$words,'blocks'=>$parsed['blocks'],'warnings'=>$warnings,'recommendation'=>$review?'REVIEW':'KEEP']; } if(!$out)throw new InvalidArgumentException('No readable spine content was found.'); return $out; }
    private function xhtml(string $xml, string $href, string $bookTitle): array
    {
        $doc = $this->xml($xml, true);
        $xp = new DOMXPath($doc);

        foreach (
            $xp->query(
                '//*[local-name()="script" or local-name()="style" or local-name()="form" or local-name()="svg"]'
            ) ?: [] as $node
        ) {
            $node->parentNode?->removeChild($node);
        }

        $title = $this->extractDisplayTitle($xp, $href, $bookTitle);
        $blocks = [];

        foreach (
            $xp->query(
                '//*[local-name()="h1" or local-name()="h2" or local-name()="h3" or local-name()="h4" or local-name()="h5" or local-name()="h6" or local-name()="p" or local-name()="li" or local-name()="blockquote"]'
            ) ?: [] as $node
        ) {
            $text = preg_replace('/\s+/u', ' ', trim($node->textContent)) ?? '';
            if ($text !== '') {
                $blocks[] = [
                    'type' => strtolower($node->localName),
                    'text' => $text,
                    'path' => $this->domPath($node),
                ];
            }
        }

        // Some EPUBs keep the story in divs, spans or loose text instead of paragraphs. When the usual elements
        // capture only a small part of the page's text, read the text itself, one paragraph per block-level break.
        $body = $xp->query('//*[local-name()="body"]')?->item(0);
        if ($body instanceof DOMNode) {
            $bodyLength = mb_strlen((string) preg_replace('/\s+/u', ' ', trim($body->textContent)));
            $got = array_sum(array_map(static fn (array $b): int => mb_strlen((string) $b['text']), $blocks));
            if ($bodyLength > 300 && $got < $bodyLength * 0.5) {
                $loose = self::looseBlocks($body, $this);
                $looseLength = array_sum(array_map(static fn (array $b): int => mb_strlen((string) $b['text']), $loose));
                if ($looseLength > $got * 1.5) $blocks = $loose;
            }
        }

        return ['title' => $title, 'blocks' => $blocks];
    }

    /**
     * Paragraphs read straight from the text of a page: block-level elements and double line breaks end a paragraph.
     * @return list<array{type:string,text:string,path:string}>
     */
    private static function looseBlocks(DOMNode $root, self $service): array
    {
        $blockTags = ['div', 'p', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'table', 'tr', 'td', 'th',
            'blockquote', 'pre', 'figure', 'figcaption', 'center', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
        $out = [];
        $buffer = '';
        $owner = $root;
        $kind = 'p';
        $brs = 0;
        $flush = static function () use (&$out, &$buffer, &$owner, &$kind, $service): void {
            $text = trim((string) preg_replace('/\s+/u', ' ', $buffer));
            $buffer = '';
            if ($text === '') return;
            $out[] = ['type' => $kind, 'text' => $text, 'path' => $service->domPath($owner)];
        };
        $walk = static function (DOMNode $node) use (&$walk, &$buffer, &$owner, &$kind, &$brs, $flush, $blockTags): void {
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                    if (trim($child->nodeValue ?? '') !== '') $brs = 0;
                    $buffer .= $child->nodeValue;
                    $owner = $node;
                } elseif ($child instanceof DOMElement) {
                    $name = strtolower($child->localName);
                    if ($name === 'br') {
                        if (++$brs >= 2) { $flush(); $brs = 0; } else $buffer .= ' ';
                    } elseif (in_array($name, $blockTags, true)) {
                        $flush();
                        $kind = preg_match('/^h[1-6]$/', $name) === 1 ? $name : 'p';
                        $walk($child);
                        $flush();
                        $kind = 'p';
                        $brs = 0;
                    } else {
                        $walk($child);
                    }
                }
            }
        };
        $walk($root);
        $flush();
        return $out;
    }

    private function extractDisplayTitle(
        DOMXPath $xp,
        string $href,
        string $bookTitle
    ): string {
        $normalizedBookTitle = mb_strtolower($this->cleanTitle($bookTitle));

        foreach (['h1', 'h2'] as $headingName) {
            foreach ($xp->query('//*[local-name()="' . $headingName . '"]') ?: [] as $node) {
                $text = $this->cleanTitle((string) $node->textContent);
                if (
                    $this->isUsefulTitle($text) &&
                    ($normalizedBookTitle === '' || mb_strtolower($text) !== $normalizedBookTitle)
                ) {
                    return $text;
                }
            }
        }

        $documentTitle = $this->cleanTitle(
            (string) ($xp->query('//*[local-name()="title"]')?->item(0)?->textContent ?? '')
        );

        if (
            $this->isUsefulTitle($documentTitle) &&
            ($normalizedBookTitle === '' || mb_strtolower($documentTitle) !== $normalizedBookTitle)
        ) {
            return $documentTitle;
        }

        return $this->filenameToTitle($href);
    }
    private function isUsefulTitle(string $title): bool
    {
        return $title !== '' && mb_strlen($title) <= 160;
    }

    private function cleanTitle(string $title): string
    {
        return trim(preg_replace('/\s+/u', ' ', $title) ?? '');
    }

    private function filenameToTitle(string $href): string
    {
        $name = rawurldecode(pathinfo(explode('#', $href, 2)[0], PATHINFO_FILENAME));
        $name = preg_replace('/^[0-9]+[\s._-]*/u', '', $name) ?? $name;
        $name = preg_replace('/[_-]+/u', ' ', $name) ?? $name;
        $name = $this->cleanTitle($name);

        if ($name === '') {
            return 'Untitled Section';
        }

        if (preg_match('/^chapter\s*0*([0-9]+)([a-z]?)$/iu', $name, $matches)) {
            return 'Chapter ' . (int) $matches[1] . strtolower($matches[2]);
        }

        return preg_replace_callback(
            '/(^|\s)(\p{L})/u',
            static fn (array $matches): string =>
                $matches[1] . mb_strtoupper($matches[2]),
            $name
        ) ?? $name;
    }
    private function xml(string $xml,bool $htmlFallback=false): DOMDocument { $doc=new DOMDocument(); $old=libxml_use_internal_errors(true); $flags=LIBXML_NONET|LIBXML_COMPACT|LIBXML_NOERROR|LIBXML_NOWARNING; $ok=$doc->loadXML($xml,$flags); if(!$ok&&$htmlFallback)$ok=$doc->loadHTML($xml,$flags); libxml_clear_errors(); libxml_use_internal_errors($old); if(!$ok)throw new InvalidArgumentException('An EPUB XML document could not be parsed.'); return $doc; }
    private function read(ZipArchive $zip,string $name): string { $value=$zip->getFromName($name); if($value===false)throw new InvalidArgumentException('Missing EPUB item: '.$name); return $value; }
    private function resolve(string $base,string $href): string { $href=rawurldecode(explode('#',$href,2)[0]); $parts=[]; foreach(explode('/',$base.$href) as $part){if($part===''||$part==='.')continue;if($part==='..'){if(!$parts)throw new InvalidArgumentException('Unsafe EPUB reference.');array_pop($parts);}else$parts[]=$part;} return implode('/',$parts); }
    private function domPath(DOMNode $node): string { $parts=[]; while($node instanceof DOMElement){$index=1;for($s=$node->previousSibling;$s;$s=$s->previousSibling)if($s instanceof DOMElement&&$s->localName===$node->localName)$index++;$parts[]=strtolower($node->localName).'['.$index.']';$node=$node->parentNode;} return '/'.implode('/',array_reverse($parts)); }
    private function uuid(): string { $data=random_bytes(16);$data[6]=chr((ord($data[6])&15)|64);$data[8]=chr((ord($data[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($data),4)); }
}
