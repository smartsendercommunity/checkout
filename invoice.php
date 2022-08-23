<?php

//CheckOut.php Powered by Smart Sender


//------------------

ini_set('max_execution_time', '1700');
set_time_limit(1700);


header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Content-Type: json; charset=utf-8');

http_response_code(200);

//------------------

$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, TRUE); //convert JSON into array

//------------------

$userid = $input["userid"];
$text = $input["text"];
$button = $input["button"];
$tg_token = "token";         //  Токен бота указать в этой стоке вместо слова token внутри кавычек
$tg_id = $input["chat_id"];
$bitrix = $input["bitrix"];
$deal_id = $input["deal_id"];
$ss_token = $input["token"];
$out_data = $input["out_data"];
$count = $input["count"];
$md5 = md5($ss_token);
$api_url = "https://api.smartsender.com";

// functions
{
function str_split_unicode($str, $l = 0) {
if ($l > 0) {
    $ret = array();
    $len = mb_strlen($str, "UTF-8");
    for ($i = 0; $i < $len; $i += $l) {
        $ret[] = mb_substr($str, $i, $l, "UTF-8");
    }
    return $ret;
}
return preg_split("//u", $str, -1, PREG_SPLIT_NO_EMPTY);
}
function send_forward($inputJSON, $link){
	
$request = 'POST';	
		
$descriptor = curl_init($link);

 curl_setopt($descriptor, CURLOPT_POSTFIELDS, $inputJSON);
 curl_setopt($descriptor, CURLOPT_RETURNTRANSFER, 1);
 curl_setopt($descriptor, CURLOPT_HTTPHEADER, array('Content-Type: application/json')); 
 curl_setopt($descriptor, CURLOPT_CUSTOMREQUEST, $request);

    $itog = curl_exec($descriptor);
    curl_close($descriptor);

   		 return $itog;
		
}
function send_bearer($url, $token, $type = "GET", $param = []){
	
		
$descriptor = curl_init($url);

 curl_setopt($descriptor, CURLOPT_POSTFIELDS, json_encode($param));
 curl_setopt($descriptor, CURLOPT_RETURNTRANSFER, 1);
 curl_setopt($descriptor, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Authorization: Bearer '.$token)); 
 curl_setopt($descriptor, CURLOPT_CUSTOMREQUEST, $type);

    $itog = curl_exec($descriptor);
    curl_close($descriptor);

   		 return $itog;
		
}
}

// Проверка входящих данных
if ($ss_token == NULL || $userid == NULL) {
    $result["status"] = "error";
    if ($ss_token == NULL) {
        $result["message"][] = "Вы не указали токен SmartSender. Он нужен для получения информации.";
    }
    if ($userid == NULL) {
        $result["message"][] = "Вы не указали идентификатор пользователя. Система не знает, чью информацию нужно использовать.";
    }
    echo json_encode($result);
    exit;
}

// Курс валют
$allCurrency = json_decode(file_get_contents("https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange?json"), true);
if (is_array($allCurrency)) {
    foreach ($allCurrency as $oneCurrency) {
        $currency[$oneCurrency["cc"]] = $oneCurrency["rate"];
    }
}

// Получение данных из корзины
if ($out_data == "checkout") {
    $cursor = json_decode(send_bearer($api_url."/v1/contacts/".$userid."/checkout?page=1&limitation=20", $ss_token), true);
    if ($cursor["error"] != NULL && $cursor["error"] != 'undefined') {
        $result["status"] = "error";
        $result["message"][] = "Ошибка получения данных из SmartSender";
        if ($cursor["error"]["code"] == 404 || $cursor["error"]["code"] == 400) {
            $result["message"][] = "Пользователь не найден. Проверте правильность идентификатора пользователя и приналежность токена к текущему проекту.";
        } else if ($cursor["error"]["code"] == 403) {
            $result["message"][] = "Токен проекта SmartSender указан неправильно. Проверте правильность токена.";
        }
        echo json_encode($result);
        exit;
    } else if (empty($cursor["collection"])) {
        $result["status"] = "error";
        $result["message"][] = "Корзина пользователя пустая. Для тестирования добавте товар в корзину.";
        $result["message"][] = "Обратите внимание, что успешная оплата заказа очищает корзину.";
        $result["message"][] = "После оплаты используйте 'out_data':'invoice' в теле запроса.";
        echo json_encode($result);
        exit;
    }
    $pages = $cursor["cursor"]["pages"];
    for ($i = 1; $i <= $pages; $i++) {
        $checkout = json_decode (send_bearer($api_url."/v1/contacts/".$userid."/checkout?page=".$i."&limitation=20", $ss_token), true);
    	$essences = $checkout["collection"];
    	$currency_itog = $essences[0]["currency"];
    	foreach ($essences as $product) {
    		$message = $message.$product["product"]["name"].': '.$product["name"].' - '.$product["pivot"]["quantity"].' x '.$product["amount"].PHP_EOL;
    		if ($product["cash"]["currency"] == "UAH") {
    		    $summUAH[] = $product["pivot"]["quantity"] * $product["cash"]["amount"];
    		} else {
    		    $summUAH[] = $product["pivot"]["quantity"] * $product["cash"]["amount"] * $currency[$product["cash"]["currency"]];
    		}
    		$summ[] = $product["pivot"]["quantity"] * $product["cash"]["amount"];
    		$tovar[] = $product["product"]["name"].': '.$product["name"].' - '.$product["pivot"]["quantity"].' x '.$product["amount"].' = '.$product["pivot"]["quantity"]*$product["cash"]["amount"];
    		$bx_tovar["PRODUCT_NAME"] = $product["product"]["name"].': '.$product["name"];
    		$bx_tovar["PRICE"] = $product["amount"];
    		$bx_tovar["QUANTITY"] = $product["pivot"]["quantity"];
    		$bx[] = $bx_tovar;
    	}
    }
    
// Поиск последнего оплаченого счета в истории контакта
} else if ($out_data == "invoice") {
    $cursor = json_decode(send_bearer($api_url."/v1/contacts/".$userid."/invoices?page=1&limitation=20", $ss_token), true);
    if ($cursor["error"] != NULL && $cursor["error"] != 'undefined') {
        $result["status"] = "error";
        $result["message"][] = "Ошибка получения данных из SmartSender.";
        if ($cursor["error"]["code"] == 404 || $inform["error"]["code"] == 400) {
            $result["message"][] = "Пользователь не найден. Проверте правильность идентификатора пользователя и приналежность токена к текущему проекту.";
        } else if ($cursor["error"]["code"] == 403) {
            $result["message"][] = "Токен проекта SmartSender указан неправильно. Проверте правильность токена.";
        }
        echo json_encode($result);
        exit;
    } else if (empty($cursor["collection"])) {
        $result["status"] = "error";
        $result["message"][] = "У пользователя нет счетов";
        echo json_encode($result);
        exit;
    }
    $pages = $cursor["cursor"]["pages"];
    for ($i = 1; $i <= $pages; $i++) {
        $allInvoice = json_decode(send_bearer($api_url."/v1/contacts/".$userid."/invoices?page=".$i."&limitation=20", $ss_token), true);
        if (is_array($allInvoice["collection"]) === true) {
            foreach ($allInvoice["collection"] as $oneInvoice) {
                if (is_array($oneInvoice["logs"]) === true) {
                    foreach($oneInvoice["logs"] as $oneInvoiceLogs) {
                        if ($oneInvoiceLogs["state"] == true) {
                            $time = strtotime($oneInvoiceLogs["createdAt"]);
                            $trueInvoice[$time] = $oneInvoice["orderId"];
                        }
                    }
                }
            }
        }
    }
    // Сортировка счетов по дате успешной оплаты
    krsort($trueInvoice);
    $orderId = current($trueInvoice);
    // Получение данных счета
    if ($orderId != NULL && $orderId != false) {
        $checkout = json_decode (send_bearer($api_url."/v1/contacts/".$userid."/invoices/".$orderId, $ss_token), true);
        if ($checkout["essence"] != NULL) {
        	$product = $checkout["essence"];
		$currency_itog = $product["currency"];
        	$message = $message.$product["product"]["name"].': '.$product["name"].' - '.$product["pivot"]["quantity"].' x '.$product["amount"].PHP_EOL;
        	if ($product["cash"]["currency"] == "UAH") {
    		    $summUAH[] = $product["pivot"]["quantity"] * $product["cash"]["amount"];
    		} else {
    		    $summUAH[] = $product["pivot"]["quantity"] * $product["cash"]["amount"] * $currency[$product["cash"]["currency"]];
    		}
    		$summ[] = $product["pivot"]["quantity"] * $product["cash"]["amount"];
    		$tovar[] = $product["product"]["name"].': '.$product["name"].' - '.$product["pivot"]["quantity"].' x '.$product["amount"].' = '.$product["pivot"]["quantity"]*$product["cash"]["amount"];
    		$bx_tovar["PRODUCT_NAME"] = $product["product"]["name"].': '.$product["name"];
    		$bx_tovar["PRICE"] = $product["amount"];
    		$bx_tovar["QUANTITY"] = $product["pivot"]["quantity"];
    		$bx[] = $bx_tovar;
        } else if ($checkout["essences"] != NULL) {
        	$essences = $checkout["essences"];
        	$currency_itog = $essences[0]["currency"];
        	foreach ($essences as $product) {
        		$message = $message.$product["product"]["name"].': '.$product["name"].' - '.$product["pivot"]["quantity"].' x '.$product["amount"].PHP_EOL;
        		if ($product["cash"]["currency"] == "UAH") {
        		    $summUAH[] = $product["pivot"]["quantity"] * $product["cash"]["amount"];
        		} else {
        		    $summUAH[] = $product["pivot"]["quantity"] * $product["cash"]["amount"] * $currency[$product["cash"]["currency"]];
        		}
        		$summ[] = $product["pivot"]["quantity"]*$product["cash"]["amount"];
        		$tovar[] = $product["product"]["name"].': '.$product["name"].' - '.$product["pivot"]["quantity"].' x '.$product["amount"].' = '.$product["pivot"]["quantity"]*$product["cash"]["amount"];
        		$bx_tovar["PRODUCT_NAME"] = $product["product"]["name"].': '.$product["name"];
    		    $bx_tovar["PRICE"] = $product["amount"];
    		    $bx_tovar["QUANTITY"] = $product["pivot"]["quantity"];
    		    $bx[] = $bx_tovar;
        	}
        } else {
            $result["status"] = "error";
            $result["message"][] = "Ошибка обработки счета. Сообщите информацию https://t.me/mufik";
            $result["message"][] = "Также предоставте полное тело запроса и код ".$orderId;
            $result["invoice"] = $checkout;
            echo json_encode($result);
            exit;
        }
    } else {
        $result["status"] = "error";
        $result["message"][] = "У пользователя не найдено успешно оплаченных счетов";
        echo json_encode($result);
        exit;
    }
} else {
    $result["status"] = "error";
    $result["message"][] = "Вы не указали, откуда получать информацию.";
    if ($out_data == NULL) {
        $result["message"][] = "Добавте елемент out_data в тело запроса. Возможные значения: ";
        $result["message"][] = "'checkout' - для получения информации из корзины,";
        $result["message"][] = "'invoice' - для получения информации с оплаченого счета";
    } else {
        $result["message"][] = "Исправте елемент out_data в теле запроса. Возможные значения: ";
        $result["message"][] = "'checkout' - для получения информации из корзины,";
        $result["message"][] = "'invoice' - для получения информации с оплаченого счета";
    }
    echo json_encode($result);
    exit;
}

// Получение счетчика
if (file_exists('invoice_count.json') === true) {
    $get_count =  file_get_contents('invoice_count.json');
    $file_count = json_decode($get_count, true);
}

if ($file_count[$md5] == NULL) {
    $file_count[$md5] = 1;
} else {
    $file_count[$md5]++;
}
if ($count == "restart") {
    $file_count[$md5] = 1;
} else if ($count > 1 && $count != NULL) {
    $file_count[$md5] = $count;
}
$json_count = json_encode($file_count);
file_put_contents('invoice_count.json', $json_count);

if (is_array($summ)) {
    $summ_itog = array_sum($summ);
}
if (is_array($summUAH)) {
    $summUAH_itog = array_sum($summUAH);
}
$text_message = $message.'Общая сумма заказа: '.$summ_itog.' '.$currency_itog.' ('.$summUAH_itog.' UAH)\n------\n'.$text;
$to_vars = str_split_unicode($message, 250);

// Отправка уведомления в телеграмм
if ($tg_id != NULL) {
    if (is_array($button)) {
        foreach ($button as $key => $url) {
            $inline_key["text"] = $key;
            if ($inline_key["url"] == "chats") {
                
            } else {
                $inline_key["url"] = $url;
            }
            $inline_keyboard[][0] = $inline_key;
        }
        $inline = json_encode($inline_keyboard);
	$sendTG["chat_id"] = $tg_id;
	$sendTG["text"] = "Новый заказ: № ".$file_count[$md5]."\n".$text_message;
	$sendTG["reply_markup"]["inline_keyboard"] = $inline_keyboard;
        $curl = curl_init();
        curl_setopt_array($curl, array(
          CURLOPT_URL => 'https://api.telegram.org/bot'.$tg_token.'/sendMessage',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_POSTFIELDS => json_encode($sendTG),
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json'
          ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);
    } else {
	$sendTG["chat_id"] = $tg_id;
	$sendTG["text"] = "Новый заказ: № ".$file_count[$md5]."\n".$text_message;
        $curl = curl_init();
        curl_setopt_array($curl, array(
          CURLOPT_URL => 'https://api.telegram.org/bot'.$tg_token.'/sendMessage',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_POSTFIELDS => json_encode($sendTG),
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json'
          ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);
    }
    $tg_otvet = json_decode($response, true);
    if (stripos($tg_otvet["description"], "chat not found")) {
        $result["status"] = "error";
        $result["message"][] = "Чат не найден. Проверте Id чата. Добавте @smartcustombot в чат"; 
    } else if (stripos($tg_otvet["description"], "group chat was upgraded to a supergroup chat")) {
        $new_tg_id = $tg_otvet["parameters"]["migrate_to_chat_id"];
        $result["status"] = "migrate_chat";
        $result["message"][] = "Чат был обновлен к уровню супергруппы";
        $result["message"][] = "Новый Id чата: ".$new_tg_id;
        $result["message"][] = "Мы отправили сообщение, но Id чата нужно изменить в запросе";
    } else if ($tg_otvet["ok"] === false) {
        $result["status"] = "error";
        $result["message"][] = "Ошибка отправки сообщения. Ниже ответ от API Telegram";
        $result["telegramAPI"] = $tg_otvet;
    } else {
        $result["message"][] = "Уведомление отправлено";
    }
}

if ($result["status"] == "migrate_chat") {
    if (is_array($button)) {
	$sendTG["text"] = $sendTG["text"]."\n------\nЭтот чат превратился в супергруппу. Пожалуйста замените Id чата в запросах. Новый chat_id ".$new_tg_id;
        $curl = curl_init();
        curl_setopt_array($curl, array(
          CURLOPT_URL => 'https://api.telegram.org/bot'.$tg_token.'/sendMessage',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_POSTFIELDS => json_encode($sendTG),
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json'
          ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);
    } else {
        $sendTG["text"] = $sendTG["text"]."\n------\nЭтот чат превратился в супергруппу. Пожалуйста замените Id чата в запросах. Новый chat_id ".$new_tg_id;
        $curl = curl_init();
        curl_setopt_array($curl, array(
          CURLOPT_URL => 'https://api.telegram.org/bot'.$tg_token.'/sendMessage',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_POSTFIELDS => json_encode($sendTG),
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json'
          ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);
    }
}

// Работаем с bitrix24
if ($bitrix != NULL) {
    if ($deal_id != NULL) {
        $send_deal["id"] = $deal_id;
    } else {
        if ($contact_id != NULL) {
            $create_deal["fields"]["CONTACT_ID"] = $contact_id;
        } else {
            // Получение данных о контакте и отправка в битрикс (создание)
            $curl = curl_init();
            curl_setopt_array($curl, array(
              CURLOPT_URL => 'https://api.smartsender.com/v1/contacts/'.$userid.'/info',
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_ENCODING => '',
              CURLOPT_MAXREDIRS => 10,
              CURLOPT_TIMEOUT => 0,
              CURLOPT_FOLLOWLOCATION => true,
              CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
              CURLOPT_CUSTOMREQUEST => 'GET',
              CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer '.$ss_token.''
              ),
            ));
            $response = curl_exec($curl);
            curl_close($curl);
            $user_data = json_decode($response, true);
            $create_contact["fields"]["NAME"] = $user_data["firstName"];
            $create_contact["fields"]["LAST_NAME"] = $user_data["lastName"];
            $create_contact["fields"]["COMMENTS"] = "userId: ".$userid;
            $create_contact["fields"]["UTM_SOURCE"] = $user_data["utm_source"];
            $create_contact["fields"]["UTM_MEDIUM"] = $user_data["utm_medium"];
            $create_contact["fields"]["UTM_CAMPAIGN"] = $user_data["utm_campaing"];
            $create_contact["fields"]["UTM_CONTENT"] = $user_data["utm_content"];
            $create_contact["fields"]["UTM_TERM"] = $user_data["utm_term"];
            $create_contact["fields"]["PHONE"][0]["VALUE"] = $user_data["phone"];
            $create_contact["fields"]["EMAIL"][0]["VALUE"] = $user_data["email"];
            $json_create_contact = json_encode($create_contact);
            $result_create_contact = send_forward($json_create_contact, $bitrix.'crm.contact.add.json');
            $result_contact = json_decode($result_create_contact, true);
            $create_deal["fields"]["CONTACT_ID"] = $result_contact["result"];
        }
        // Создание сделки для контакта в Битрикс
        $result["bitrix"]["contact_id"] = $create_deal["fields"]["CONTACT_ID"];
        $create_deal["fields"]["COMMENTS"] = $text.'<br/><br/>Заказ: '.$file_count[$md5];
        $json_create_deal = json_encode($create_deal);
        $result_create_deal = send_forward($json_create_deal, $bitrix.'crm.deal.add.json');
        $result_deal = json_decode($result_create_deal, true);
        $send_deal["id"] = $result_deal["result"];
    }
    // Добавление товаров в сделку битрикс
    $send_deal["rows"] = $bx;
    $result["bitrix"]["deal_id"] = $send_deal["id"];
    $json_send_deal = json_encode($send_deal);
    send_forward($json_send_deal, $bitrix.'crm.deal.productrows.set.json');
}




// Возвращение ответа
$result["count"] = $file_count[$md5];
$result["summ"] = $summ_itog;
$result["currency"] = $currency_itog;
$result["amount"]["UAH"] = round($summUAH_itog, 2);
$result["amount"]["USD"] = round($summUAH_itog / $currency["USD"], 2);
$result["amount"]["EUR"] = round($summUAH_itog / $currency["EUR"], 2);
$result["amount"]["GBP"] = round($summUAH_itog / $currency["GBP"], 2);
$result["amount"]["PLN"] = round($summUAH_itog / $currency["PLN"], 2);
$result["tovar"] = $tovar;
$result["to_vars"] = $to_vars;
echo json_encode($result);
