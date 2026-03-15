<?php

declare(strict_types=1);

auth_logout();
flash_set('success', 'Logged out.');
header('Location: index.php?page=login');
exit;

