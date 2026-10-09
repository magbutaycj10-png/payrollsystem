<?php
// The portal used to have its own sign-in page. There is now a single login
// for admins, managers and employees at /index.php - anything still pointing
// here (a bookmark, an old link) is forwarded to it.
header('Location: /index.php' . (isset($_GET['logout']) ? '?logout=1' : ''));
exit;
