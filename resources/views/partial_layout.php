<?php /* Teilantwort für Seitenwechsel innerhalb der App (Renderer::fragment):
         nur der Titel für document.title und <main>. Kein <head>, kein Vite -
         Skripte und Stile stehen bereits im Dokument. */ ?>
<title><?= e($title ?? 'Notizen & Tasks') ?></title>
<?= $content ?? '' ?>
