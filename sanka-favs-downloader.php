<?php

require_once 'init.php';

function curl_get_contents($url, $options = [])
{
    echo "Downloading $url ...\n";
    static $ch;

    usleep(2500000);

    if (!$ch) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        #curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        #curl_setopt($ch, CURLOPT_VERBOSE, true);

        $cookiejar_filename = "/tmp/down-php-cookiejar-" . uniqid();
        curl_setopt($ch, CURLOPT_COOKIESESSION, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookiejar_filename);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookiejar_filename);
    }

    $headers = ['User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:78.0) Gecko/20100101 Firefox/78.0', 'Connection: keep-alive'];

    if (isset($options['postfields'])) {
        curl_setopt($ch, CURLOPT_HTTPGET, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $options['postfields']);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } else {
        curl_setopt($ch, CURLOPT_POST, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, null);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_URL, $url);

    $curl_result = curl_exec($ch);

    #var_dump(curl_getinfo($ch, CURLINFO_COOKIELIST));
    #file_put_contents(time(), $curl_result);

    return $curl_result;
}

function sanitize_for_filename($string)
{
    return substr(trim(str_replace('/', '-', $string), '-'), 0, 200);
}

//login
$userpass = urlencode($userpass);
curl_get_contents('https://chan.sankakucomplex.com/user/authenticate', ['postfields' => "url=&user%5Bname%5D=$username&user%5Bpassword%5D=$userpass&commit=Login"]);

//get favs
$posts_to_download = [];

for ($page = 1; $page < 100; $page++) {
    #post-list
    $url = "https://chan.sankakucomplex.com/?tags=fav%3A$username";
    if ($page > 1) {
        $url .= "&page=$page";
    }
    $fav_page = curl_get_contents($url);
    preg_match_all('/\/post\/show\/[0-9]+/', $fav_page, $matches);
    #var_dump($matches[0]);

    if (count($matches[0]) < 2) {
        // if we flip past the last page, there's only one /post/, the user's avatar. So we have all
        break;
    }

    $posts_to_download = array_merge($posts_to_download, $matches[0]);
    #break
}

$posts_to_download = array_unique($posts_to_download);
#var_dump($posts_to_download);
#exit;

$work_dir = './posts';
if (!is_dir($work_dir)) {
    mkdir($work_dir);
}

$download_archive = file('download-archive.txt', FILE_IGNORE_NEW_LINES);
if (!$download_archive) {
    $download_archive = [];
}

$numerrors = 0;
$numfavs = count($posts_to_download);
$numdl = count($download_archive);
echo "Found $numfavs posts to download. Download archive already has $numdl of them.\n";

foreach ($posts_to_download as $post_url_path) {
    $url = 'https://chan.sankakucomplex.com' . $post_url_path;

    if (in_array($url, $download_archive)) {
        echo "$url already in download archive, skipping ...\n";
        continue;
    }

    //get post page
    $image_page = curl_get_contents($url);
    preg_match('/Original:[^"]+"([^"]+)"/', $image_page, $matches);

    $original_image_url = 'https:' . $matches[1];
    $original_image_url = html_entity_decode($original_image_url);

    preg_match('/\.([A-Za-z]+)\?/', $original_image_url, $matches);
    $original_image_extension = $matches[1];

    #var_dump($original_image_url);
    #var_dump($original_image_extension);

    preg_match('/<title>(.*)<\/title>/', $image_page, $matches);
    list($title) = explode(' | ', $matches[1]);

    $image_file_name = $work_dir . '/' . sanitize_for_filename($title) . '.' . $original_image_extension;
    #var_dump($image_file_name);

    //get original image
    $original_image = curl_get_contents($original_image_url);

    file_put_contents($image_file_name, $original_image);

    if (filesize($image_file_name) > 20 * 1024) {
        file_put_contents('download-archive.txt', $url . "\n", FILE_APPEND);
    } else {
        echo "FAILED!\n";
        $numerrors++;
    }

    if ($numerrors > 5) {
        echo "NUMBER OF ALLOWABLE ERRORS EXCEEDED, STOPPING\n";
        exit();
    }

    #exit;
}

echo "FINISHED\n";
