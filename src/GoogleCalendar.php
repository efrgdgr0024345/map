<?php
declare(strict_types=1);
namespace App;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/** User-selected calendar reading; writes are confined to an app-created calendar. */
final class GoogleCalendar
{
    public const SCOPES='https://www.googleapis.com/auth/calendar.events.readonly https://www.googleapis.com/auth/calendar.app.created';
    private const API='https://www.googleapis.com/calendar/v3/';
    public function __construct(private array $config, private $transport=null)
    {
        self::validateConfig($config);
    }
    public static function validateConfig(array $config): void
    {
        $url=parse_url((string)($config['redirectUri'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/D',(string)($config['clientId'] ?? ''))
            || strlen((string)($config['clientSecret'] ?? ''))<10 || strlen($config['clientSecret'])>512
            || !$url || ($url['scheme'] ?? '')!=='https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || !str_ends_with($url['path'] ?? '','/calendar.php')) {
            throw new InvalidArgumentException('Enter a Google web OAuth client ID, client secret and the exact HTTPS calendar.php callback URL.');
        }
    }
    public static function loadConfig(): ?array
    {
        $path=APP_VAR.'/google-calendar.php';
        if (!is_file($path) || is_link($path) || filesize($path)>8192) { return null; }
        $content=file_get_contents($path); $guard="<?php http_response_code(404); exit; ?>\n";
        if (!str_starts_with($content,$guard)) { return null; }
        try { $config=json_decode(substr($content,strlen($guard)),true,16,JSON_THROW_ON_ERROR); self::validateConfig($config); return $config; }
        catch (\Throwable) { return null; }
    }
    private function fingerprint(): string { return hash('sha256',json_encode($this->config,JSON_THROW_ON_ERROR)); }
    public function connected(array $session): bool
    {
        return isset($session['google']['accessToken']) && hash_equals($this->fingerprint(),(string)($session['google']['client'] ?? ''));
    }
    public function begin(array &$session): string
    {
        $state=bin2hex(random_bytes(32)); $verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
        $session['oauth']=['state'=>$state,'verifier'=>$verifier,'expires'=>time()+600];
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id'=>$this->config['clientId'],'redirect_uri'=>$this->config['redirectUri'],'response_type'=>'code',
            'scope'=>self::SCOPES,'state'=>$state,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),
            'code_challenge_method'=>'S256','access_type'=>'offline','prompt'=>'consent select_account']);
    }
    public function callback(array &$session,string $state,string $code): void
    {
        $pending=$session['oauth'] ?? []; unset($session['oauth']);
        if (empty($pending['state']) || !hash_equals($pending['state'],$state) || ($pending['expires'] ?? 0)<time() || $code==='' || strlen($code)>4096) {
            throw new InvalidArgumentException('Google connection expired or could not be verified. Connect again from the day planner.');
        }
        $tokens=$this->token(['code'=>$code,'code_verifier'=>$pending['verifier'],'grant_type'=>'authorization_code','redirect_uri'=>$this->config['redirectUri']]);
        $granted=preg_split('/\s+/',trim((string)($tokens['scope'] ?? '')));
        foreach (explode(' ',self::SCOPES) as $scope) { if (!in_array($scope,$granted,true)) { throw new RuntimeException('Both calendar permissions are needed. Reconnect and choose the requested permissions.'); } }
        $session['google']=['accessToken'=>$tokens['access_token'],'refreshToken'=>$tokens['refresh_token'] ?? '',
            'expires'=>time()+max(1,(int)($tokens['expires_in'] ?? 3600))-60,'client'=>$this->fingerprint()];
    }
    private function token(array $fields): array
    {
        $response=$this->send('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query($fields+['client_id'=>$this->config['clientId'],'client_secret'=>$this->config['clientSecret']]));
        if ($response['status']!==200 || empty($response['data']['access_token'])) { throw new RuntimeException('Google could not authorise this connection. Check the OAuth setup or reconnect your account.'); }
        return $response['data'];
    }
    private function request(array &$session,string $method,string $path,?array $body=null): array
    {
        if (!$this->connected($session)) { throw new RuntimeException('Connect Google Calendar first.'); }
        $auth=&$session['google'];
        if (($auth['expires'] ?? 0)<=time()) {
            if (empty($auth['refreshToken'])) { unset($session['google']); throw new RuntimeException('Google connection expired. Connect again.'); }
            try { $tokens=$this->token(['grant_type'=>'refresh_token','refresh_token'=>$auth['refreshToken']]); }
            catch (\Throwable $e) { unset($session['google']); throw $e; }
            $auth['accessToken']=$tokens['access_token']; $auth['expires']=time()+max(1,(int)($tokens['expires_in'] ?? 3600))-60;
        }
        $response=$this->send($method,self::API.$path,['Authorization: Bearer '.$auth['accessToken'],'Content-Type: application/json'],$body===null ? null : json_encode($body,JSON_THROW_ON_ERROR));
        if ($response['status']<200 || $response['status']>=300) {
            if ($response['status']===401) { unset($session['google']); }
            throw new RuntimeException($response['status']===401 ? 'Google connection expired. Connect again.' : 'Google Calendar could not complete that request. Check permissions or try again.');
        }
        return $response['data'];
    }
    public function events(array &$session,string $date): array
    {
        $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('Australia/Perth'));
        if (!$day || $day->format('Y-m-d')!==$date || $day<new DateTimeImmutable('yesterday',new \DateTimeZone('Australia/Perth')) || $day>new DateTimeImmutable('+14 days',new \DateTimeZone('Australia/Perth'))) { throw new InvalidArgumentException('Choose a calendar day in the next fourteen days.'); }
        $data=$this->request($session,'GET','calendars/primary/events?'.http_build_query(['timeMin'=>$day->format(DATE_ATOM),'timeMax'=>$day->modify('+1 day')->format(DATE_ATOM),'singleEvents'=>'true','orderBy'=>'startTime','maxResults'=>100]));
        $events=[]; $allowed=[];
        foreach ($data['items'] ?? [] as $event) {
            if (($event['status'] ?? '')==='cancelled' || empty($event['id'])) { continue; }
            $item=['id'=>$event['id'],'title'=>mbSafe($event['summary'] ?? 'Untitled event',160),'location'=>mbSafe($event['location'] ?? '',300),
                'start'=>$event['start']['dateTime'] ?? null,'end'=>$event['end']['dateTime'] ?? null,'allDay'=>!isset($event['start']['dateTime'])];
            $events[]=$item; $allowed[$item['id']]=$item;
        }
        $session['calendarEvents']=$allowed;
        return ['events'=>$events,'moreAvailable'=>isset($data['nextPageToken'])];
    }
    /** No event is changed: explicit previewed travel blocks go to Nextstop Trips. */
    public function addTravel(array &$session,array $blocks): array
    {
        if (count($blocks)<1 || count($blocks)>12) { throw new InvalidArgumentException('Choose between one and twelve travel reminders.'); }
        $valid=[];
        foreach ($blocks as $block) {
            if (!is_array($block)) { throw new InvalidArgumentException('Choose valid travel blocks.'); }
            foreach (['title','start','end','details'] as $field) { if (isset($block[$field]) && !is_string($block[$field])) { throw new InvalidArgumentException('Choose valid travel block details.'); } }
            $start=self::dateTime($block['start'] ?? ''); $end=self::dateTime($block['end'] ?? '');
            if ($start<time()-120 || $start>time()+14*86400 || $end<=$start || $end-$start>8*3600) { throw new InvalidArgumentException('Choose future travel blocks up to eight hours long.'); }
            $title=trim((string)($block['title'] ?? ''));
            if ($title==='' || strlen($title)>1024) { throw new InvalidArgumentException('Choose a short travel reminder title.'); }
            $valid[]=['title'=>$title,'start'=>date(DATE_ATOM,$start),'end'=>date(DATE_ATOM,$end),'details'=>mbSafe($block['details'] ?? '',3000)];
        }
        if (empty($session['google']['calendarId'])) {
            $calendar=$this->request($session,'POST','calendars',['summary'=>'Nextstop Trips','timeZone'=>'Australia/Perth','description'=>'Travel plans explicitly added from Nextstop. Times follow the timetable; delays are not included.']);
            if (empty($calendar['id'])) { throw new RuntimeException('Google did not return the travel calendar.'); }
            $session['google']['calendarId']=$calendar['id'];
        }
        $calendarId=rawurlencode($session['google']['calendarId']); $added=[];
        foreach ($valid as $block) {
            // Deterministic Google event IDs make retrying a partial write safe.
            $id=hash('sha256',json_encode($block,JSON_THROW_ON_ERROR));
            if (isset($session['addedTravel'][$id])) { $added[]=$session['addedTravel'][$id]; continue; }
            $body=['id'=>$id,'summary'=>$block['title'],'description'=>$block['details'],'start'=>['dateTime'=>$block['start'],'timeZone'=>'Australia/Perth'],
                'end'=>['dateTime'=>$block['end'],'timeZone'=>'Australia/Perth'],'reminders'=>['useDefault'=>false,'overrides'=>[['method'=>'popup','minutes'=>10],['method'=>'popup','minutes'=>0]]]];
            try { $event=$this->request($session,'POST','calendars/'.$calendarId.'/events',$body); }
            catch (RuntimeException $e) {
                // A previous insert may have succeeded before the response was lost.
                $event=$this->request($session,'GET','calendars/'.$calendarId.'/events/'.$id);
                if (($event['id'] ?? '')!==$id) { throw $e; }
            }
            $saved=['id'=>$event['id'],'title'=>$block['title']]; $session['addedTravel'][$id]=$saved; $added[]=$saved;
        }
        if (count($session['addedTravel'] ?? [])>200) { $session['addedTravel']=array_slice($session['addedTravel'],-100,null,true); }
        return ['added'=>$added,'calendar'=>'Nextstop Trips','message'=>'Enable Nextstop Trips and notifications in Google Calendar on your phone.'];
    }
    public function disconnect(array &$session): void
    {
        $token=$session['google']['refreshToken'] ?? $session['google']['accessToken'] ?? null;
        unset($session['google'],$session['oauth'],$session['calendarEvents'],$session['addedTravel']);
        if ($token) { try { $this->send('POST','https://oauth2.googleapis.com/revoke',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['token'=>$token])); } catch (\Throwable) {} }
    }
    public static function dateTime(string $value): int
    {
        $normalized=str_ends_with($value,'Z') ? substr($value,0,-1).'+00:00' : $value;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$normalized); $errors=DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format(DATE_ATOM)!==$normalized) { throw new InvalidArgumentException('Choose a valid date and time with a timezone.'); }
        return $date->getTimestamp();
    }
    private function send(string $method,string $url,array $headers,?string $body=null): array
    {
        if ($this->transport) { return ($this->transport)($method,$url,$headers,$body); }
        $curl=curl_init($url); $response='';
        curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$response): int { if (strlen($response)+strlen($chunk)>5*1024*1024) { return 0; } $response.=$chunk; return strlen($chunk); }]);
        if ($body!==null) { curl_setopt($curl,CURLOPT_POSTFIELDS,$body); }
        $ok=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); $curl=null;
        if ($ok===false) { throw new RuntimeException('Google connection is unavailable. Try again.'); }
        $data=$response==='' ? [] : json_decode($response,true);
        return ['status'=>$status,'data'=>is_array($data) ? $data : []];
    }
}

/** PHP mbstring is optional on cPanel. */
function mbSafe(string $value,int $limit): string { return function_exists('mb_substr') ? mb_substr($value,0,$limit) : substr($value,0,$limit); }
