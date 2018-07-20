<?php
/*	Модуль предоставляет функции для работы с URL.
*/

	
/*	Абсолютизирует URL.
		$rel_url - исходный относительный (или абсолютный) URL
		$base_url - абсолютный URL страницы, где был найден исходный URL (можно с http:// или без).
	Возвращает абсолютный URL.
	Работает согласно стандартам, так же, как это делают современные браузеры. 
	Если $base_url имеет неизвестную схему URL (отличающуюся от 'http', 'https', 'ftp'), либо если она отсутствует, то она будет заменена на 'http'.
	Если $rel_url содержит email-адрес или другую неизвестную схему URL (javascript, mailto, skype, итд), то функция вернет $base_url без изменений.
	А именно, поддерживаются:
		- пустой относительный URL (означает текущий адрес)
		- использование схемы URL из базового URL (когда $rel_url начинается на "//")
		- относительные в рамках текущей папки
		- относительные (содержащие "./" и "/./"), означает адрес текущей директории
		- относительные с переходом вверх по папке (содержат "../")
		- относительные от корня (начинаются на "/")
		- относительные от знака вопроса (начинаются на "?")
		- относительные от хеша (начинаются на "#")
	Функция хорошо протестирована.
*/
function url_abs($rel_url, $base_url)
{
	$rel_url = trim($rel_url);
	if (!preg_match('#^(https?|ftp)://#i', $base_url))
	{$base_url = 'http://'.$base_url;}
	if (preg_match('#^//[\w\-]+\.[\w\-]+#i', $rel_url))
	{$rel_url = parse_url($base_url, PHP_URL_SCHEME).':'.$rel_url;}
	if (!strlen($rel_url))
	{return $base_url;}
	if (preg_match('#^(https?|ftp)://#i', $rel_url))
	{return $rel_url;}
	if (preg_match('#^[a-z]+:#i', $rel_url))
	{return $base_url;}
	if (in_array($rel_url{0}, ['?', '#']))
	{return reset(explode($rel_url{0}, $base_url, 2)).$rel_url;}
	$p = parse_url($base_url);
	$pp = (($rel_url{0}=='/')?'':preg_replace('#/[^/]*$#', '', $p['path']));
	$abs = $p['host'].$pp.'/'.$rel_url;
	if (!preg_match('#^(https?|ftp)$#i', $p['scheme']))
	{$p['scheme'] = 'http';}
	if (preg_match('#^(.*?)([\?\#].*)$#s', $abs, $m))
	{$abs = $m[1];}
	do {$abs = preg_replace(['#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#'], '/', $abs, -1, $n);}
	while ($n>0);
	do {$abs = preg_replace('#/\.\./#', '/', $abs, -1, $n);}
	while ($n>0);
	$abs .= $m[2];
	$s = $p['scheme'].'://'.$abs;
	return $s;
}

/*	Заменить "голые" домены и URLы на содержимое, задаваемое функцией.
	Ищет очень точно URLы и домены. Неправильные домены игнорирует.
	Учитывает newTLD домены и Punycode-домены.
	Поможет очистить строку от *текстовых* внешних ссылок и упоминаний сторонних доменов.
		$s - строка
		$func - функция вида: function($url){ ... }
			Должна вернуть строку, которой будет заменен найденный URL.
			Если не задана, то домены будут просто удалены.
	Внимание! Замены не производится в следующих случаях:
		- URL находящиеся внутри HTML-атрибутов: "href", "src", "srcset", "action", "data", "poster", "cite"
		- URL находящиеся внутри STYLE-конструкции: url("...") или url(...)
		- URL заключенные в некоторый HTML-тег, т.е. находящиеся внутри (определяются эвристически)
*/
function url_replace($s, $func = NULL)
{
	// + полное доменное имя не может быть длиннее 255 символов
	$r = '#(?<=[^\w\.\-]|^)((https?:)?//)?[a-z\d\-]{1,63}(\.[a-z\d\-]{1,63}){0,5}\.(?!aac|ai|aif|apk|arj|asp|aspx|atom|avi|bak|bat|bin|bmp|cab|cda|cer|cfg|cfm|cgi|class|cpl|cpp|cs|css|csv|cur|dat|db|dbf|deb|dll|dmg|dmp|doc|drv|ejs|eot|eps|exe|flv|fnt|fon|gif|gz|htm|icns|ico|img|ini|iso|jad|jar|java|jpeg|jpg|js|json|jsp|key|lnk|log|mdb|mid|midi|mkv|mov|mpa|mpeg|mpg|msi|odf|odp|ods|odt|ogg|otf|part|pdf|php|pkg|pls|png|pps|ppt|pptx|psd|py|rar|rm|rpm|rss|rtf|sav|sql|svg|svgz|swf|swift|sys|tar|tex|tgz|tif|tmp|toast|ttf|txt|vb|vcd|vob|wav|wbmp|webm|webp|wks|wma|wmv|woff|wpd|wpl|wps|wsf|xhtml|xlr|xls|xml|zip)(xn--[a-z\d\-]{1,63}|[a-z]{2,11})(:\d+)?(?=[^\w\.\-]|$)[^\[\]<>"\']*?(?=$|[^a-z\-\d/\.])#ui';
	if (!preg_match_all($r, $s, $m, PREG_OFFSET_CAPTURE)) return $s;
	$m = $m[0];
	if (!$func) $func = function(){return;};
	foreach ($m as &$mm)
	{
		$x = max(0, $mm[1]-4000);
		$q = substr($s, $x, $mm[1]-$x);
		$q2 = substr($s, $mm[1]+strlen($mm[0]), 4000);
		$host = parse_url('http://'.preg_replace(['#^https?:#i', '#^//#'], '', $mm[0]), PHP_URL_HOST);
		if (strlen($host)>255 || 
			preg_match('#(=["\']|(\s(href|src|srcset|action|data|poster|cite)=|\burl\()["\']?)[^"\'<>\(\)\n]*$#i', $q) ||
			(preg_match('#>\s*$#', $q) && preg_match('#^\s*</#', $q2))
		) continue;
		$mm['func'] = $func($mm[0]);
	}
	unset($mm);
	$prev = 0; $res = '';
	foreach ($m as &$mm)
	{
		$res .= substr($s, $prev, $mm[1]-$prev).(array_key_exists('func', $mm)?$mm['func']:$mm[0]);
		$prev = $mm[1]+strlen($mm[0]);
	}
	$res .= substr($s, $prev);
	return $res;
}

/*	Скачивает контент по указанному URL.
		$allow_404 - возвращать содержимое даже для страниц с кодом ответа 404 (если отключено, то будет возвращать NULL).
	Возвращает массив вида:
		['исходный код страницы', 'содержимое Content-Type']
*/
function cu_download($url, $allow_404 = true, $timeout = 20)
{
	$url = preg_replace('/#.*/', '', $url);
	if ($ch = curl_init())
	{
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		@curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:61.0) Gecko/20100101 Firefox/61.0');
		curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: en-us,en;q=0.5'));
		curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
		curl_setopt($ch, CURLOPT_REFERER, $url);
		$res = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if (!$allow_404 && $code==404)
		{$res = NULL;}
		$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
		$header = substr($res, 0, $header_size);
		$res = substr($res, $header_size);
		$err = curl_error($ch);
		if ($err!='') 
		{echo 'CURL ERROR: '.$err."\n\n";}
		curl_close($ch);
	}
	return [$res, $content_type];
}