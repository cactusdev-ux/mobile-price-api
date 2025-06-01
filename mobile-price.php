<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

function safeValue($value) {
    return ($value === null || $value === '') ? null : $value;
}

function formatPrice($price) {
    if (!$price || $price === 0) return null;
    return [
        'rial' => number_format($price, 0, '', ','),
        'toman' => number_format(floor($price / 10), 0, '', ',')
    ];
}

function handleImageProxy() {
    $filename = $_GET['media'] ?? '';
    if (!$filename || !preg_match('/^[a-zA-Z0-9_\-]+\.(jpg|jpeg|png|webp)$/', $filename)) {
        http_response_code(400);
        echo 'نام فایل تصویر مجاز نیست.';
        return;
    }

    $url = "https://dkstatics-public.digikala.com/digikala-products/$filename";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_HEADER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    if (curl_error($ch)) {
        curl_close($ch);
        http_response_code(404);
        echo 'تصویر در دسترس نیست یا خطایی هنگام دریافت آن رخ داده است.';
        return;
    }

    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    if (preg_match('/Content-Type:\s*([^\r\n]+)/i', $headers, $matches)) {
        header('Content-Type: ' . trim($matches[1]));
    }

    curl_close($ch);
    header('Cache-Control: public, max-age=86400');
    echo $body;
}

function handleRequest() {
    if (isset($_GET['media'])) {
        handleImageProxy();
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'درخواست نامعتبر است (فقط GET مجاز است).'], JSON_UNESCAPED_UNICODE);
        return;
    }

    try {
        $searchQuery = $_GET['q'] ?? null;
        $limitQuery = $_GET['limit'] ?? null;

        if (!$searchQuery) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'لطفاً کلمه‌ی مورد نظر خود را در پارامتر «q» وارد کنید.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $url = 'https://api.digikala.com/v1/categories/mobile-phone/search/?q=' . urlencode($searchQuery) . '&sort=22&page=1&per_page=1';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json']
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_error($ch)) {
            error_log('[cURL Error] ' . curl_error($ch) . "\n", 3, __DIR__ . '/error.log');
            throw new Exception('خطایی در پردازش درخواست شما رخ داد. لطفاً بعداً تلاش کنید.');
        }

        if ($httpCode !== 200) {
            error_log("[API Error] Digikala HTTP $httpCode\n", 3, __DIR__ . '/error.log');
            throw new Exception('خطایی در پردازش درخواست شما رخ داد. لطفاً بعداً تلاش کنید.');
        }

        curl_close($ch);
        $data = json_decode($response, true);
        $products = $data['data']['products'] ?? [];

        $limit = intval($limitQuery);
        if ($limit > 0) $products = array_slice($products, 0, $limit);

        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $origin = "$protocol://{$_SERVER['HTTP_HOST']}{$_SERVER['SCRIPT_NAME']}";

        $output = [];
        foreach ($products as $product) {
            $img = $product['images']['main']['url'][0] ?? '';
            $filename = $img ? basename(parse_url($img, PHP_URL_PATH)) : '';
            $proxyUrl = $filename ? "$origin?media=$filename" : null;

            $output[] = [
                'title_fa' => safeValue($product['title_fa'] ?? null),
                'title_en' => safeValue($product['title_en'] ?? null),
                'brand' => safeValue($product['data_layer']['brand'] ?? $product['brand']['title_fa'] ?? null),
                'price' => formatPrice($product['default_variant']['price']['rrp_price'] ?? 0),
                'product_thumb' => safeValue($proxyUrl)
            ];
        }

        echo json_encode([
            'ok' => true,
            'product_count' => count($output),
            'products' => $output
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'دریافت اطلاعات محصول با مشکل مواجه شد.',
            'message' => 'خطای ناشناخته‌ای رخ داده است. پوزش می‌طلبیم.'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

handleRequest();
?>
