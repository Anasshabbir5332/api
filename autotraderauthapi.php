<?php
class AutoTrader_API {
    private $api_key;
    private $api_secret;
    private $auth_endpoint = 'https://api-sandbox.autotrader.co.uk/authenticate';
    private $stock_endpoint = 'https://api-sandbox.autotrader.co.uk/stock';
    private $advert_endpoint = 'https://api-sandbox.autotrader.co.uk/adverts';
    private $bearer_token;
    
    public function __construct() {
        if (!defined('AUTOTRADER_API_KEY') || !defined('AUTOTRADER_API_SECRET')) {
            throw new Exception('AutoTrader API credentials are not configured in wp-config.php');
        }
        $this->api_key = AUTOTRADER_API_KEY;
        $this->api_secret = AUTOTRADER_API_SECRET;
    }
    
    public function authenticate() {
        $data = http_build_query([
            'key' => $this->api_key, 
            'secret' => $this->api_secret 
        ]);
    
        $ch = curl_init($this->auth_endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
    
        if ($http_code !== 200) {
            error_log("AutoTrader Authentication Failed - HTTP Code: $http_code, Error: $error");
            return 'Failed to authenticate';
        }
    
        $body = json_decode($response, true);
        
        if (isset($body['access_token'])) {
            $this->bearer_token = $body['access_token'];
            return $this->bearer_token;
        }
        
        error_log("AutoTrader Authentication Failed - No access token in response");
        return 'Failed to authenticate';
    }
    
    public function get_stock_data($advertiser_id = '10012495', $page = 1, $page_size = 100) {
        if (!$this->bearer_token) {
            $auth_result = $this->authenticate();
            if ($auth_result !== $this->bearer_token) {
                return json_encode(['error' => 'Authentication failed']);
            }
        }

        $url = $this->stock_endpoint . '?advertiserId=' . urlencode($advertiser_id) . 
               '&page=' . urlencode($page) . '&pageSize=' . urlencode($page_size);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->bearer_token,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
    
        if (WP_DEBUG) {
            error_log("AutoTrader API Response - HTTP Code: " . $http_code);
            error_log("AutoTrader API Response - Error: " . $error);
            error_log("AutoTrader API URL: " . $url);
        }
    
        return $response;
    }

    public function get_advert_data($advert_id) {
        if (!$this->bearer_token) {
            $auth_result = $this->authenticate();
            if ($auth_result !== $this->bearer_token) {
                return json_encode(['error' => 'Authentication failed']);
            }
        }

        $url = $this->advert_endpoint . '/' . urlencode($advert_id);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->bearer_token,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
    
        if (WP_DEBUG) {
            error_log("AutoTrader Advert API Response - HTTP Code: " . $http_code);
            error_log("AutoTrader Advert API Response - Error: " . $error);
        }
    
        return $response;
    }

    public function get_bearer_token() {
        return $this->bearer_token;
    }
}
