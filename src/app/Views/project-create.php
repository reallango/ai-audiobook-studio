<?php declare(strict_types=1); $pageTitle='Create Project'; require APP_ROOT.'/app/Views/layout-top.php'; ?>
<section class="page-head"><div><p class="eyebrow">NEW BOOK</p><h1>Create project</h1><p class="lead compact">This creates the permanent project container. EPUB upload is intentionally handled in the next phase.</p></div></section>
<section class="form-panel"><?php if($errors): ?><div class="error-list"><strong>Correct the following:</strong><ul><?php foreach($errors as $error):?><li><?=e($error)?></li><?php endforeach;?></ul></div><?php endif; ?>
<form method="post" action="<?=e(base_url('project-create'))?>"><?=csrf_field()?>
<label>Book title<input name="title" maxlength="200" required autofocus value="<?=e((string)$values['title'])?>" placeholder="The Autobiography of Benjamin Sisko"></label>
<div class="form-row"><label>TTS provider<select name="provider"><option value="kokoro" <?=($values['provider']==='kokoro')?'selected':''?>>Kokoro, OpenAI-compatible</option></select></label><label>Voice profile<input name="voice" maxlength="100" value="<?=e((string)$values['voice'])?>" placeholder="Example: bm_lewis"></label></div>
<label>Section audio quality<select name="bitrate"><option value="64" <?=((int)$values['bitrate']===64)?'selected':''?>>64 kbps AAC, draft</option><option value="96" <?=((int)$values['bitrate']===96)?'selected':''?>>96 kbps AAC, default</option><option value="128" <?=((int)$values['bitrate']===128)?'selected':''?>>128 kbps AAC, high</option></select></label>
<div class="form-actions"><a class="button secondary" href="<?=e(base_url())?>">Cancel</a><button type="submit">Create project</button></div></form></section>
<?php require APP_ROOT.'/app/Views/layout-bottom.php'; ?>
