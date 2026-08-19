<?php
require dirname(__DIR__) . '/config.php';
session_destroy();
redirect('index.php');
