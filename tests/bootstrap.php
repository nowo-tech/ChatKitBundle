<?php

declare(strict_types=1);
use Symfony\Component\Filesystem\Filesystem;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('Run composer install in ChatKitBundle before executing tests.');
}

require $autoload;

// Every run starts from a fresh test kernel cache: a container (translation catalogues, Twig) compiled by an earlier run
// with other translations or templates must never leak into this one.
(new Filesystem())->remove(sys_get_temp_dir() . '/nowo-chat-kit-bundle');
