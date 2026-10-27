<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

try {
    $config=App\GoogleCalendar::loadConfig();
    $action=$_GET['action'] ?? 'status';
    if (!is_string($action)) { throw new InvalidArgumentException('Invalid calendar action.'); }
    if (!$config) {
        if ($action==='status') { header('Content-Type: application/json'); echo json_encode(['configured'=>false,'connected'=>false,'message'=>'Google Calendar needs the site owner to complete OAuth setup in loader.php.']); exit; }
        throw new RuntimeException('Google Calendar has not been configured by the site owner.');
    }
    // Private sessions are separated from maintenance sessions and kept out of
    // shared hosting's default garbage-collection directory.
    $directory=APP_VAR.'/calendar-sessions';
    if (is_link($directory) || (!is_dir($directory) && !mkdir($directory,0700,true))) { throw new RuntimeException('Calendar session storage is unavailable.'); }
    $deny=$directory.'/.htaccess';
    if (is_link($deny)) { throw new RuntimeException('Calendar session storage is unsafe.'); }
    if (!is_file($deny)) { file_put_contents($deny,"Require all denied\n"); chmod($deny,0600); }
    ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1'); ini_set('session.gc_maxlifetime','2592000');
    ini_set('session.gc_probability','1'); ini_set('session.gc_divisor','100');
    session_save_path($directory); session_name('nextstop_calendar');
    $path=rtrim(dirname(parse_url($config['redirectUri'],PHP_URL_PATH)),'/').'/';
    session_set_cookie_params(['lifetime'=>2592000,'path'=>$path,'secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    if (!session_start()) { throw new RuntimeException('Calendar session could not start.'); }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $google=new App\GoogleCalendar($config);
    if (isset($_GET['code']) || isset($_GET['error'])) {
        if (isset($_GET['error'])) {
            if (!is_string($_GET['state'] ?? null) || !isset($_SESSION['oauth']['state']) || !hash_equals($_SESSION['oauth']['state'],$_GET['state'])) { throw new InvalidArgumentException('Google callback could not be verified. Connect again.'); }
            unset($_SESSION['oauth']); $_SESSION['calendarNotice']='Google connection was cancelled. You can try again when ready.';
        }
        else {
            if (!is_string($_GET['state'] ?? null) || !is_string($_GET['code'])) { throw new InvalidArgumentException('Invalid Google callback.'); }
            $google->callback($_SESSION,$_GET['state'],$_GET['code']); session_regenerate_id(true);
            $_SESSION['csrf']=bin2hex(random_bytes(32)); $_SESSION['calendarNotice']='Google Calendar connected. Select an event to plan its travel.';
        }
        session_write_close(); header('Location: '.$path.'?planner=calendar',true,303); exit;
    }
    if ($action==='status') {
        $result=['configured'=>true,'connected'=>$google->connected($_SESSION),'csrf'=>$_SESSION['csrf'],'notice'=>$_SESSION['calendarNotice'] ?? null]; unset($_SESSION['calendarNotice']);
    } elseif ($action==='events' && $_SERVER['REQUEST_METHOD']==='GET') {
        $date=$_GET['date'] ?? ''; if (!is_string($date)) { throw new InvalidArgumentException('Choose a calendar date.'); }
        $result=$google->events($_SESSION,$date);
    } else {
        if ($_SERVER['REQUEST_METHOD']!=='POST') { throw new InvalidArgumentException('Use the calendar controls to confirm this action.'); }
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>65536) { throw new InvalidArgumentException('Calendar request is too large.'); }
        $body=file_get_contents('php://input',false,null,0,65537); if (strlen($body)>65536) { throw new InvalidArgumentException('Calendar request is too large.'); }
        $input=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($input) || !is_string($input['csrf'] ?? null) || !hash_equals($_SESSION['csrf'],$input['csrf'])) { http_response_code(403); throw new InvalidArgumentException('Calendar confirmation expired. Reopen the planner.'); }
        if ($action==='connect') { $result=['url'=>$google->begin($_SESSION)]; }
        elseif ($action==='disconnect') { $google->disconnect($_SESSION); session_regenerate_id(true); $result=['connected'=>false]; }
        elseif ($action==='add-travel') {
            if (($input['confirmed'] ?? false)!==true || !is_array($input['blocks'] ?? null)) { throw new InvalidArgumentException('Review the travel times and explicitly confirm adding them.'); }
            $result=$google->addTravel($_SESSION,$input['blocks']);
        } else { throw new InvalidArgumentException('Unknown calendar action.'); }
    }
    if (session_status()===PHP_SESSION_ACTIVE) { session_write_close(); }
    header('Content-Type: application/json; charset=utf-8'); echo json_encode($result,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    if (session_status()===PHP_SESSION_ACTIVE) { session_write_close(); }
    if (http_response_code()<400) { http_response_code($error instanceof InvalidArgumentException ? 400 : 503); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error'=>$error instanceof JsonException ? 'Invalid calendar request.' : (($error instanceof InvalidArgumentException || $error instanceof RuntimeException) ? $error->getMessage() : 'Calendar request could not complete. Try again.')],JSON_INVALID_UTF8_SUBSTITUTE);
}
