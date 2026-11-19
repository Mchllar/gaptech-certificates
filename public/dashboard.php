<?php
/**
 * The dashboard is now the "Today" section at the top of Reports (see app/today.php).
 * This address stays so old bookmarks and links still land in the right place.
 */
require __DIR__ . '/../app/bootstrap.php';

require_staff();
redirect('reports.php');
