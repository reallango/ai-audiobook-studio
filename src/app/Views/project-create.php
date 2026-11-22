<?php declare(strict_types=1); $pageTitle='Create Project'; require APP_ROOT.'/app/Views/layout-top.php'; ?>
<section class="page-head"><div><p class="eyebrow">NEW BOOK</p><h1>Create project</h1><p class="lead compact">This creates the permanent project container. EPUB upload is intentionally handled in the next phase.</p></div></section>
<section class="form-panel"><?php if($errors): ?><div class="error-list"><strong>Correct the following:</strong><ul><?php foreach($errors as $error):?><li><?=e($error)?></li><?php endforeach;?></ul></div><?php endif; ?>
<form method="post" action="<?=e(base_url('project-create'))?>"><?=csrf_field()?>
<label>Book title<input name="title" maxlength="200" required autofocus value="<?=e((string)$values['title'])?>" placeholder="The Autobiography of Benjamin Sisko"></label>
<div class="form-row"><label>TTS provider<select name="provider"><option value="kokoro" <?=($values['provider']==='kokoro')?'selected':''?>>Kokoro, OpenAI-compatible</option></select></label><label>Voice<input name="voice" maxlength="100" value="<?=e((string)$values["voice"])?>" placeholder="Example: am_michael"></label></div>
<label>Audio quality <small>mono AAC, used for the chapter m4a files and the m4b</small><select name="bitrate"><?php foreach (\App\Services\AudioEncoder::BITRATES as $kbps => $label): ?><option value="<?= (int) $kbps ?>" <?= (int) $values['bitrate'] === $kbps ? 'selected' : '' ?>><?= (int) $kbps ?> kbps, <?= e(strtolower($label)) ?></option><?php endforeach; ?></select></label>
<div class="form-actions"><a class="button secondary" href="<?=e(base_url())?>">Cancel</a><button type="submit">Create project</button></div></form></section>
<?php require APP_ROOT.'/app/Views/layout-bottom.php'; ?>
