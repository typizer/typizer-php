<?php

/**
 * Автозагрузчик для установки без Composer (старый «Битрикс: Управление сайтом», виртуальный
 * хостинг): распакуйте архив SDK, например, в /local/php_interface/typizer и подключите
 *
 *     require_once $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/typizer/autoload.php';
 *
 * С Composer этот файл не нужен: `composer require typizer/typizer` и `vendor/autoload.php`.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Typizer\\')) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen('Typizer\\'))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
