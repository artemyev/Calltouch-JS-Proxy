<?php
/*
 * Кеширование файлов с удаленных серверов
 * init-min.js
 */

// Файл который кешируем
define('CACHE_FILE', 'https://mod.calltouch.ru/init-min.js');
// Сколько времени файл актуален
define('CACHE_FILE_TIME', 60);
// Префикс для файла кеша
define('CACHE_PREFIX', 'cache_js_calltouch_init-min_');
// Папка где храним кеш
define('CACHE_DIR', sys_get_temp_dir());

function cache($url, $min_expiration) {
    $min_expiration = max(intval($min_expiration), 1);
    $cache_key = md5($url);
    $cache_file = CACHE_PREFIX . $cache_key;
    $cache_file_fullpath = CACHE_DIR . '/' . $cache_file;
    $cache_file_mtime = @filemtime($cache_file_fullpath);

    if ($cache_file_mtime && $cache_file_mtime >= time() - $min_expiration) {
        // Есть кешированная версия, возраст которой меньше $min_expiration секунд — вернуть ее
        return $cache_file_fullpath;
    }

    $url_or_file = $cache_file_mtime ? $cache_file_fullpath : $url;

    // Пытаемся заблокировать URL-адрес: если это не удастся, либо вернем устаревшую кэшированную
    // версию (если есть), в противном случае исходный URL
    $lockn = $cache_file_fullpath . '.lock';
    $lockp = @fopen($lockn, 'w+');
    
    if ($lockp === false) {
        return $url_or_file;
    }

    if(! @flock($lockp, LOCK_EX|LOCK_NB)) {
        return $url_or_file;
    }
    
    // Есть блокировка, теперь получим URL-адрес и сохраним его во временном файле
    $fn = @tempnam(CACHE_DIR, 'remote_js_calltouch_init-min_');
    if ($fn === false) {
        return $url_or_file;
    }

    $fp = @fopen($fn, 'w+b');
    
    if ($fp === false) {
        
        return $url_or_file;
    }
    
    $c = @curl_init();
    
    if ($c === false) {
        return $url_or_file;
    }
    
    if (!@curl_setopt($c, CURLOPT_URL, $url)) {
        return $url_or_file;
    }
    
    // Перенаправим USER_AGENT, что бы нас узнали
    @curl_setopt($c, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
    
    // Тайм-аут, секунд
    @curl_setopt($c, CURLOPT_TIMEOUT, 10);
    
    // Отключим проверку SSL, вдруг чего не так
    @curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
    
    @curl_setopt($c, CURLOPT_ENCODING, 'gzip, deflate');
    
    // @todo Дебаг, заголовки
    //@curl_setopt($c, CURLOPT_HEADER, true);

    @curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
    
    // @todo Если захотим не подменять файл, а сразу его кешировать то включить это и убрать @fwrite($fp, $output);
    /*
    if (!@curl_setopt($c, CURLOPT_FILE, $fp)) {
        return $url_or_file;
    }
    */
    // Если скрипт переехал, будем кататься за ним, далее 5 раз
    if (!@curl_setopt($c, CURLOPT_FOLLOWLOCATION, true)) {
        return $url_or_file;
    }
    if (!@curl_setopt($c, CURLOPT_AUTOREFERER, true)) {
        return $url_or_file;
    }
    if (!@curl_setopt($c, CURLOPT_MAXREDIRS, 5)) {
        return $url_or_file;
    }

    // Время файла
    if ($cache_file_mtime)
        @curl_setopt($c, CURLOPT_TIMEVALUE, $cache_file_mtime);

    // Прямая отдача, далее в $body
    /*
    if (!@curl_exec($c)) {
        return $url_or_file;
    }
    */

    $body = @curl_exec($c);
    
    if (! $body) {
        return $url_or_file;
    }
    
    // Меняем то что нам нужно

    // Дополнительный скрипт d_client_new.js, направляем тоже в кеш
    $output = preg_replace(
        "/(.*)(https\:\/\/\"\.concat\([a-zA-Z],\"\/d_client_new\.js\?param\;\"\))(.*)/iu",
        "$1https://\".concat(\"www.ВАШ_ДОМЕН.ru\",\"/d_client_new.js.php?param;\")$3",
        $body
    );

    // Дополнительный скрипт d_client_new.js, направляем тоже в кеш
    $output = preg_replace(
        "/(.*)(\.src\.indexOf\([a-z]\+\"\/d_client_new\.js\"\))(.*)/iu",
        "$1.src.indexOf(\"ВАШ_ДОМЕН.ru/d_client_new.js.php\")$3",
        $output
    );
    
    // Дополнительный скрипт d_client_new.js, направляем тоже в кеш
    $output = preg_replace(
        "/(.*)(\.src\.match\(\/calltouch\.\(ru\|net\)\\\\\/d_client_new\\\.js\/\))(.*)/iu",
        "$1.src.match(/(ВАШ_ДОМЕН).ru\/d_client_new\.js\.php/)$3",
        $output
    );

    // @todo Тут нужно подменить запросы на корректный домен
    $output = preg_replace(
        "/(.*)\,([a-zA-Z])(\=window\.location\.protocol\+\"\/\/\"\+)([a-zA-Z])\,(.*)/iu",
        "$1,$2=window.location.protocol+\"//mod.calltouch.ru\"+$4,$5",
        $output
    );
    
    // Эмитрируем что скрипт с калчата
    $output = str_replace(
        "document.currentScript",
        "{src: \"https://mod.calltouch.ru/init-min.js\"}",
        $output
    );
    
    // Эмитрируем что скрипт с калчата, дописыаем заголовок, но не точно)
    $output = preg_replace(
        "/(.*)([\(\,])([a-zA-Z])\.(setRequestHeader)(.*)/iu",
        "$1$2$3.$4(\"Access-Control-Allow-Origin\", \"*\"),$3.$4$5",
        $output
    );

    @fwrite($fp, $output);

    $cs = intval(@curl_getinfo($c, CURLINFO_HTTP_CODE));
    @curl_close($c);

    // Закончили получение URL-адреса: если оно завершилось успешно, удалить старую кэш
    // версию и заменить ее новой
    if ($cs >= 200 && $cs < 300) {
        if (!@unlink($cache_file_fullpath)) {
            // @todo Чего то не удаляется, отключил
            //return $url;
        }

        if(!@link($fn, $cache_file_fullpath)) {
            return $url;
        }
    }

    // снять блокировку, закрыть и удалить временные файлы
    @fclose($fp);
    @fclose($lockp);
    @unlink($fn);
    @unlink($lockn);
    
    return $cache_file_fullpath;
}

/**
 * Получение файла
 *
 * param @url string
 * param @min_expiration int
 *
 */
function getContents($url, $min_expiration=60) {
    
    $filePath = cache($url, $min_expiration);

    return file_get_contents($filePath);
}

// Заголовок, что бы не портить картину
@header('Content-type: text/javascript');

// Парсим QUERY что бы передать их оригиналу
$url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);

// Вывод
print getContents(CACHE_FILE . (! empty($url) ? '?' . $url : ''), CACHE_FILE_TIME);

