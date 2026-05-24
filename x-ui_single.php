<?php
require_once 'config.php';
require_once 'request.php';
require_once 'x-ui_auth.php';
ini_set('error_log', 'error_log');
function xuiCookiePath($code_panel)
{
    $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $code_panel);
    return __DIR__ . '/cookie-' . $safeCode . '.txt';
}

function panel_login_cookie($code_panel)
{
    $panel = select("marzban_panel", "*", "code_panel", $code_panel, "select");
    $cookiePath = xuiCookiePath($code_panel);
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $panel['url_panel'] . '/login',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT_MS => 4000,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => "username={$panel['username_panel']}&password=" . urlencode($panel['password_panel']),
        CURLOPT_COOKIEJAR => $cookiePath,
    ));
    $response = curl_exec($curl);
    if (curl_error($curl)) {
        $error = curl_error($curl);
        curl_close($curl);
        return json_encode(array(
            'success' => false,
            'msg' => $error
        ));
    }
    curl_close($curl);
    return $response;
}
function xui_apply_auth(CurlRequest $req, array $panel)
{
    $apiToken = xuiPanelApiToken($panel);
    if ($apiToken !== null) {
        $req->setBearerToken($apiToken);
        return false;
    }

    login($panel['code_panel']);
    $req->setCookie(xuiCookiePath($panel['code_panel']));
    return true;
}

function xui_cleanup_auth($usedCookie, array $panel)
{
    if (!$usedCookie) {
        return;
    }

    $cookiePath = xuiCookiePath($panel['code_panel']);
    if (is_file($cookiePath)) {
        @unlink($cookiePath);
    }
}

function login($code_panel, $verify = true)
{
    $panel = select("marzban_panel", "*", "code_panel", $code_panel, "select");
    if (xuiUsesBearerAuth($panel)) {
        $url = $panel['url_panel'] . '/panel/api/inbounds/list';
        $req = new CurlRequest($url);
        $req->setHeaders(array(
            'Accept: application/json',
            'Content-Type: application/json',
        ));
        $req->setBearerToken(xuiPanelApiToken($panel));
        $response = $req->get();
        $decoded = isset($response['body']) ? json_decode($response['body'], true) : null;
        if (is_array($decoded)) {
            return $decoded;
        }

        return array(
            'success' => isset($response['status']) && $response['status'] >= 200 && $response['status'] < 300,
            'msg' => $response['error'] ?? ('HTTP ' . ($response['status'] ?? 'unknown')),
        );
    }

    $cookiePath = xuiCookiePath($panel['code_panel']);
    if ($panel['datelogin'] != null && $verify) {
        $date = json_decode($panel['datelogin'], true);
        if (isset($date['time'])) {
            $timecurrent = time();
            $start_date = time() - strtotime($date['time']);
            if ($start_date <= 3000) {
                file_put_contents($cookiePath, $date['access_token']);
                return array('success' => true, 'msg' => 'Using cached session');
            }
        }
    }
    $response = panel_login_cookie($panel['code_panel']);
    $time = date('Y/m/d H:i:s');
    $data = json_encode(array(
        'time' => $time,
        'access_token' => is_file($cookiePath) ? file_get_contents($cookiePath) : ''
    ));
    update("marzban_panel", "datelogin", $data, 'name_panel', $panel['name_panel']);
    if (!is_string($response))
        return array('success' => false);
    return json_decode($response, true);
}

function get_clinets($username, $namepanel)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $url = $marzban_list_get['url_panel'] . "/panel/api/inbounds/getClientTraffics/$username";
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $usedCookie = xui_apply_auth($req, $marzban_list_get);
    $response = $req->get();

    if (isset($response['body'])) {
        $decodedBody = json_decode($response['body'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedBody)) {
            if (isset($decodedBody['success']) && $decodedBody['success'] === false) {
                $response['error'] = $decodedBody['msg'] ?? 'Unknown panel error';
            }
        }
    }

    if (!empty($response['error'])) {
        error_log(json_encode($response));
    }

    xui_cleanup_auth($usedCookie, $marzban_list_get);

    return $response;
}

function xuiParseInboundIds($raw)
{
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,]+/', $raw);
    }

    $ids = [];
    foreach ($parts as $part) {
        $id = intval($part);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

function xuiAddClientInbounds($namepanel, $usernameac, $Expire, $Total, $Flow, $subid, $inboundIds, $name_product, $note = "")
{
    $inboundIds = xuiParseInboundIds($inboundIds);
    if (empty($inboundIds)) {
        return [
            'error' => 'No inbound IDs configured',
        ];
    }

    $uuid = generateUUID();
    $lastResponse = null;

    foreach ($inboundIds as $inboundId) {
        $response = addClient($namepanel, $usernameac, $Expire, $Total, $uuid, $Flow, $subid, $inboundId, $name_product, $note);
        $lastResponse = $response;

        if (!empty($response['error'])) {
            return $response;
        }
        if (!empty($response['status']) && $response['status'] != 200) {
            return $response;
        }

        $body = json_decode($response['body'] ?? '', true);
        if (is_array($body) && array_key_exists('success', $body) && !$body['success']) {
            return $response;
        }
    }

    return $lastResponse;
}

function addClient($namepanel, $usernameac, $Expire, $Total, $Uuid, $Flow, $subid, $inboundid, $name_product, $note = "")
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    if ($name_product == "usertest") {
        if ($marzban_list_get['on_hold_test'] == "1") {
            if ($Expire == 0) {
                $timeservice = 0;
            } else {
                $timelast = $Expire - time();
                $timeservice = -intval(($timelast / 86400) * 86400000);
            }
        } else {
            $timeservice = $Expire * 1000;
        }
    } else {
        if ($marzban_list_get['conecton'] == "onconecton") {
            if ($Expire == 0) {
                $timeservice = 0;
            } else {
                $timelast = $Expire - time();
                $timeservice = -intval(($timelast / 86400) * 86400000);
            }
        } else {
            $timeservice = $Expire * 1000;
        }
    }
    $config = array(
        "id" => intval($inboundid),
        'settings' => json_encode(array(
            'clients' => array(
                array(
                    "id" => $Uuid,
                    "flow" => $Flow,
                    "email" => $usernameac,
                    "totalGB" => $Total,
                    "expiryTime" => $timeservice,
                    "enable" => true,
                    "tgId" => "",
                    "subId" => $subid,
                    "reset" => 0,
                    "comment" => $note
                )
            ),
            'decryption' => 'none',
            'fallbacks' => array(),
        ))
    );
    if (!isset($usernameac))
        return array(
            'status' => 500,
            'msg' => 'username is null'
        );
    $configpanel = json_encode($config, true);
    $url = $marzban_list_get['url_panel'] . '/panel/api/inbounds/addClient';
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $usedCookie = xui_apply_auth($req, $marzban_list_get);
    $response = $req->post($configpanel);
    xui_cleanup_auth($usedCookie, $marzban_list_get);
    return $response;
}
function updateClient($namepanel, $uuid, array $config)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $configpanel = json_encode($config, true);
    $url = $marzban_list_get['url_panel'] . '/panel/api/inbounds/updateClient/' . $uuid;
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $usedCookie = xui_apply_auth($req, $marzban_list_get);
    $response = $req->post($configpanel);
    xui_cleanup_auth($usedCookie, $marzban_list_get);
    return $response;
}
function ResetUserDataUsagex_uisin($usernamepanel, $namepanel)
{
    $data_user = get_clinets($usernamepanel, $namepanel);
    $data_user = json_decode($data_user['body'], true)['obj'];
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $url = $marzban_list_get['url_panel'] . "/panel/api/inbounds/{$data_user['inboundId']}/resetClientTraffic/" . $usernamepanel;
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $usedCookie = xui_apply_auth($req, $marzban_list_get);
    $response = $req->post(array());
    xui_cleanup_auth($usedCookie, $marzban_list_get);
    return $response;
}
function removeClient($location, $username)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $location, "select");
    $url = $marzban_list_get['url_panel'] . "/panel/api/inbounds/{$marzban_list_get['inboundid']}/delClientByEmail/" . $username;
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $usedCookie = xui_apply_auth($req, $marzban_list_get);
    $response = $req->post(array());
    xui_cleanup_auth($usedCookie, $marzban_list_get);
    return $response;
}