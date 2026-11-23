<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;use App\Repositories\LogicalSectionRepository;use InvalidArgumentException;use Throwable;
final class LogicalSectionService
{
 public function updateAll(string$p,array$changes):void{$this->revision($p,'bulk_update','Titles, inclusion, or order changed.');$db=Database::connection();$db->beginTransaction();try{$temp=$db->prepare('UPDATE logical_sections SET position=:n WHERE project_id=:p AND id=:id');foreach($changes as$i=>$r)$temp->execute(['n'=>-100000-$i,'p'=>$p,'id'=>$r['id']]);$q=$db->prepare('UPDATE logical_sections SET position=:n,title=:t,included=:i,updated_at=CURRENT_TIMESTAMP WHERE project_id=:p AND id=:id');foreach($changes as$i=>$r)$q->execute(['n'=>$i+1,'t'=>$r['title'],'i'=>$r['included'],'p'=>$p,'id'=>$r['id']]);$db->commit();}catch(Throwable$e){if($db->inTransaction())$db->rollBack();throw$e;}}
 public function combine(string$p,array$ids):void{if(count($ids)<2)throw new InvalidArgumentException('Select at least two adjacent logical sections.');$repo=new LogicalSectionRepository();$rows=[];foreach($ids as$id){$r=$repo->find($p,$id);if(!$r)throw new InvalidArgumentException('Section not found.');$rows[]=$r;}usort($rows,fn($a,$b)=>(int)$a['position']<=>(int)$b['position']);for($i=1;$i<count($rows);$i++)if((int)$rows[$i]['position']!==(int)$rows[$i-1]['position']+1)throw new InvalidArgumentException('Only adjacent sections can be combined.');$this->revision($p,'combine','Combined '.count($rows).' adjacent logical sections.');$db=Database::connection();$db->beginTransaction();try{$target=$rows[0]['id'];$order=(int)$db->query("SELECT COALESCE(MAX(source_order),-1)+1 FROM logical_section_sources WHERE logical_section_id=".$db->quote($target))->fetchColumn();$move=$db->prepare('UPDATE logical_section_sources SET logical_section_id=:target,source_order=:n WHERE logical_section_id=:source AND source_section_id=:sid');foreach(array_slice($rows,1)as$row){foreach($repo->sources($row['id'])as$source)$move->execute(['target'=>$target,'n'=>$order++,'source'=>$row['id'],'sid'=>$source['id']]);$db->prepare('DELETE FROM logical_sections WHERE id=:id')->execute(['id'=>$row['id']]);}$this->renumber($db,$p);$db->commit();}catch(Throwable$e){if($db->inTransaction())$db->rollBack();throw$e;}}
 public function split(string$p,string$id,string$blockId,string$title):void{$repo=new LogicalSectionRepository();$section=$repo->find($p,$id);if(!$section)throw new InvalidArgumentException('Logical section not found.');$blocks=$repo->blocks($id);$at=null;foreach($blocks as$i=>$b)if($b['id']===$blockId)$at=$i;if($at===null||$at<1||$at>=count($blocks))throw new InvalidArgumentException('Choose a block after the first block.');$this->revision($p,'split','Split '.$section['title'].' at a source block boundary.');$before=array_slice($blocks,0,$at);$after=array_slice($blocks,$at);$newId=$this->uuid();$db=Database::connection();$db->beginTransaction();try{$db->prepare('UPDATE logical_sections SET position=position+100000 WHERE project_id=:p AND position>:n')->execute(['p'=>$p,'n'=>$section['position']]);$db->prepare('INSERT INTO logical_sections(id,project_id,position,title,included)VALUES(:id,:p,:n,:t,:i)')->execute(['id'=>$newId,'p'=>$p,'n'=>$section['position']+1,'t'=>$title,'i'=>$section['included']]);$db->prepare('DELETE FROM logical_section_sources WHERE logical_section_id=:id')->execute(['id'=>$id]);$insert=$db->prepare('INSERT INTO logical_section_sources(logical_section_id,source_section_id,source_order,start_block_index,end_block_index)VALUES(:l,:s,:o,:start,:end)');$this->linksFromBlocks($insert,$id,$before);$this->linksFromBlocks($insert,$newId,$after);$db->commit();}catch(Throwable$e){if($db->inTransaction())$db->rollBack();throw$e;}}
 public function formatting(string$p,string$number,string$caps):void{if(!in_array($number,['preserve','digits','words'],true)||!in_array($caps,['preserve','title','upper'],true))throw new InvalidArgumentException('Invalid chapter formatting choice.');$this->revision($p,'title_format','Applied chapter title formatting.');$db=Database::connection();$db->beginTransaction();try{$db->prepare('INSERT INTO project_section_settings(project_id,number_style,capitalization,updated_at)VALUES(:p,:n,:c,CURRENT_TIMESTAMP) ON CONFLICT(project_id) DO UPDATE SET number_style=excluded.number_style,capitalization=excluded.capitalization,updated_at=CURRENT_TIMESTAMP')->execute(['p'=>$p,'n'=>$number,'c'=>$caps]);$q=$db->prepare('SELECT id,title FROM logical_sections WHERE project_id=:p');$q->execute(['p'=>$p]);$u=$db->prepare('UPDATE logical_sections SET title=:t,updated_at=CURRENT_TIMESTAMP WHERE id=:id');foreach($q->fetchAll()as$r){$title=$this->formatTitle($r['title'],$number,$caps);$u->execute(['t'=>$title,'id'=>$r['id']]);}$db->commit();}catch(Throwable$e){if($db->inTransaction())$db->rollBack();throw$e;}}
    /**
     * Puts the structure back as it was before the last operation. Sections that existed then are updated in place, never
     * deleted and recreated, so the work on a chapter (edits, voice and speed, approval, audio) survives. Only sections
     * created since (by a split) are removed.
     */
    public function undo(string $p): void
    {
        $repo = new LogicalSectionRepository();
        $rev = $repo->lastRevision($p);
        if (!$rev) throw new InvalidArgumentException('There is no structure operation to undo.');
        $snapshot = json_decode((string) $rev['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $now = $db->prepare('SELECT id FROM logical_sections WHERE project_id=:p');
            $now->execute(['p' => $p]);
            $current = array_map('strval', $now->fetchAll(\PDO::FETCH_COLUMN));
            $before = array_map(static fn (array $r): string => (string) $r['id'], $snapshot['sections']);

            $dropLinks = $db->prepare('DELETE FROM logical_section_sources WHERE logical_section_id=:id');
            $dropSection = $db->prepare('DELETE FROM logical_sections WHERE project_id=:p AND id=:id');
            foreach (array_diff($current, $before) as $id) {
                $dropLinks->execute(['id' => $id]);
                $dropSection->execute(['p' => $p, 'id' => $id]);
            }

            // park every position out of the way (they are unique per project), then set each section as it was
            $db->prepare('UPDATE logical_sections SET position = -position - 1000000 WHERE project_id=:p')->execute(['p' => $p]);
            $update = $db->prepare('UPDATE logical_sections SET position=:position, title=:title, included=:included, updated_at=CURRENT_TIMESTAMP WHERE project_id=:project_id AND id=:id');
            $insert = $db->prepare('INSERT INTO logical_sections(id,project_id,position,title,included,created_at,updated_at) VALUES(:id,:project_id,:position,:title,:included,:created_at,:updated_at)');
            foreach ($snapshot['sections'] as $r) {
                if (in_array((string) $r['id'], $current, true)) {
                    $update->execute(['position' => $r['position'], 'title' => $r['title'], 'included' => $r['included'], 'project_id' => $p, 'id' => $r['id']]);
                } else {
                    $insert->execute($r);
                }
            }

            $link = $db->prepare('INSERT INTO logical_section_sources(logical_section_id,source_section_id,source_order,start_block_index,end_block_index) VALUES(:logical_section_id,:source_section_id,:source_order,:start_block_index,:end_block_index)');
            foreach ($before as $id) $dropLinks->execute(['id' => $id]);
            foreach ($snapshot['links'] as $r) $link->execute($r);

            $db->prepare('UPDATE logical_structure_revisions SET undone_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['id' => $rev['id']]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
 private function revision(string$p,string$op,string$desc):void{$db=Database::connection();$s=$db->prepare('SELECT id,project_id,position,title,included,created_at,updated_at FROM logical_sections WHERE project_id=:p ORDER BY position');$s->execute(['p'=>$p]);$sections=$s->fetchAll();$l=$db->prepare('SELECT x.logical_section_id,x.source_section_id,x.source_order,x.start_block_index,x.end_block_index FROM logical_section_sources x JOIN logical_sections s ON s.id=x.logical_section_id WHERE s.project_id=:p ORDER BY s.position,x.source_order');$l->execute(['p'=>$p]);$db->prepare('INSERT INTO logical_structure_revisions(project_id,operation,description,snapshot_json)VALUES(:p,:o,:d,:j)')->execute(['p'=>$p,'o'=>$op,'d'=>$desc,'j'=>json_encode(['sections'=>$sections,'links'=>$l->fetchAll()],JSON_THROW_ON_ERROR)]);}
 private function linksFromBlocks(\PDOStatement$q,string$id,array$blocks):void{$groups=[];foreach($blocks as$b){$sid=$b['source_section_id'];$groups[$sid]??=['start'=>$b['block_index'],'end'=>$b['block_index'],'order'=>$b['source_order']];$groups[$sid]['end']=$b['block_index'];}uasort($groups,fn($a,$b)=>$a['order']<=>$b['order']);$o=0;foreach($groups as$sid=>$g)$q->execute(['l'=>$id,'s'=>$sid,'o'=>$o++,'start'=>$g['start'],'end'=>$g['end']]);}
 private function renumber(\PDO$db,string$p):void{$s=$db->prepare('SELECT id FROM logical_sections WHERE project_id=:p ORDER BY position');$s->execute(['p'=>$p]);$ids=$s->fetchAll(\PDO::FETCH_COLUMN);$q=$db->prepare('UPDATE logical_sections SET position=:n WHERE id=:id');foreach($ids as$i=>$id)$q->execute(['n'=>$i+1,'id'=>$id]);}
 private function formatTitle(string$t,string$n,string$c):string{if(preg_match('/^chapter\s+([0-9]+|[a-z -]+)$/iu',$t,$m)){$num=$m[1];if($n==='digits'&&!ctype_digit($num))$num=(string)($this->wordNumber($num)??$num);elseif($n==='words'&&ctype_digit($num))$num=$this->numberWord((int)$num);$t='Chapter '.$num;}if($c==='upper')$t=mb_strtoupper($t);elseif($c==='title')$t=mb_convert_case(mb_strtolower($t),MB_CASE_TITLE,'UTF-8');return$t;}
 private function numberWord(int$n):string{$ones=['zero','one','two','three','four','five','six','seven','eight','nine','ten','eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];$tens=[2=>'twenty',3=>'thirty',4=>'forty',5=>'fifty',6=>'sixty',7=>'seventy',8=>'eighty',9=>'ninety'];if($n<20)return$ones[$n]??(string)$n;if($n<100)return$tens[intdiv($n,10)].($n%10?' '.$ones[$n%10]:'');return(string)$n;}
 private function wordNumber(string$w):?int{$map=['one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,'seven'=>7,'eight'=>8,'nine'=>9,'ten'=>10,'eleven'=>11,'twelve'=>12,'thirteen'=>13,'fourteen'=>14,'fifteen'=>15,'sixteen'=>16,'seventeen'=>17,'eighteen'=>18,'nineteen'=>19,'twenty'=>20,'thirty'=>30,'forty'=>40,'fifty'=>50,'sixty'=>60,'seventy'=>70,'eighty'=>80,'ninety'=>90];$parts=preg_split('/[ -]+/',mb_strtolower(trim($w)));$sum=0;foreach($parts as$p){if(!isset($map[$p]))return null;$sum+=$map[$p];}return$sum?:null;}
 /**
     * Builds the first logical sections from the analyzed spine: one per source section, except that consecutive
     * "chapter_N" files of the same chapter are grouped. Sections the analysis marked REVIEW start excluded.
     * (Ported from the pre-1.0 service: the phase 1 rewrite lost it, so nothing ever created the first structure.)
     */
    public function initialize(string $projectId): int
    {
        $repository = new LogicalSectionRepository();
        if ($repository->exists($projectId)) throw new InvalidArgumentException('Logical sections already exist.');

        $statement = Database::connection()->prepare('SELECT * FROM source_sections WHERE project_id=:p ORDER BY spine_index');
        $statement->execute(['p' => $projectId]);
        $sourceSections = $statement->fetchAll();
        if (!$sourceSections) throw new InvalidArgumentException('Analyze the EPUB first.');

        $groups = [];
        foreach ($sourceSections as $sourceSection) {
            $chapterNumber = $this->chapterNumber($sourceSection);
            $lastIndex = count($groups) - 1;
            if ($chapterNumber !== null && $lastIndex >= 0 && $groups[$lastIndex]['chapter_number'] === $chapterNumber && $this->isAdjacent($groups[$lastIndex], $sourceSection)) {
                $groups[$lastIndex]['items'][] = $sourceSection;
                continue;
            }
            $groups[] = ['chapter_number' => $chapterNumber, 'items' => [$sourceSection]];
        }

        $db = Database::connection();
        $db->beginTransaction();
        try {
            $insertLogical = $db->prepare('INSERT INTO logical_sections(id,project_id,position,title,included) VALUES(:id,:p,:position,:title,:included)');
            $insertSource = $db->prepare('INSERT INTO logical_section_sources(logical_section_id,source_section_id,source_order) VALUES(:logical,:source,:source_order)');
            $position = 1;
            foreach ($groups as $group) {
                $first = $group['items'][0];
                $logicalId = $this->uuid();
                $title = $group['chapter_number'] !== null
                    ? 'Chapter ' . $group['chapter_number']
                    : trim((string) ($first['title_hint'] ?: pathinfo((string) $first['href'], PATHINFO_FILENAME)));
                if ($group['chapter_number'] === null && ($named = $this->nameFromText((string) $first['id'])) !== null) $title = $named;
                $insertLogical->execute(['id' => $logicalId, 'p' => $projectId, 'position' => $position++, 'title' => $title, 'included' => $first['initial_recommendation'] === 'KEEP' ? 1 : 0]);
                foreach ($group['items'] as $sourceOrder => $item) {
                    $insertSource->execute(['logical' => $logicalId, 'source' => $item['id'], 'source_order' => $sourceOrder]);
                }
            }
            $db->commit();
            return count($groups);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    /** A heading found in the first lines of a source section, if it has one. */
    private function nameFromText(string $sourceSectionId): ?string
    {
        $s = Database::connection()->prepare('SELECT block_type, source_text FROM source_blocks WHERE source_section_id=:s ORDER BY block_index LIMIT 6');
        $s->execute(['s' => $sourceSectionId]);
        $rows = $s->fetchAll();
        return \App\Services\SectionAdvisor::name(array_column($rows, 'source_text'), array_column($rows, 'block_type'));
    }

    /**
     * What the advisor suggests for each section: whether to keep it and a name taken from its text. Nothing is saved.
     * @return list<array{id:string,keep:bool,name:?string,words:int}>
     */
    public function suggestions(string $projectId): array
    {
        $repo = new LogicalSectionRepository();
        $out = [];
        foreach ($repo->all($projectId) as $section) {
            $blocks = $repo->blocks((string) $section['id']);
            $texts = array_column($blocks, 'source_text');
            $href = (string) ($section['composition'][0]['href'] ?? '');
            $out[] = ['id' => (string) $section['id'], 'keep' => \App\Services\SectionAdvisor::keep((string) $section['title'], $href, $texts),
                'name' => \App\Services\SectionAdvisor::name($texts, array_column($blocks, 'block_type')), 'words' => (int) $section['word_count']];
        }
        return $out;
    }

    private function chapterNumber(array $section): ?int
    {
        $candidate = pathinfo((string) $section['href'], PATHINFO_FILENAME) . ' ' . (string) $section['manifest_id'];
        return preg_match('/chapter[_\s-]*0*([0-9]+)(?:[a-z])?/iu', $candidate, $matches) ? (int) $matches[1] : null;
    }

    private function isAdjacent(array $group, array $section): bool
    {
        $last = $group['items'][count($group['items']) - 1];
        return (int) $section['spine_index'] === (int) $last['spine_index'] + 1;
    }

    private function uuid():string{$d=random_bytes(16);$d[6]=chr((ord($d[6])&15)|64);$d[8]=chr((ord($d[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));}
}
