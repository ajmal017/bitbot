<?php

namespace biz;

use Curl\Curl;
use biz\InfoService;
use \Exception;
use \Redis;
use model\Trade;

class TradeService
{
	
	public function __construct()
	{
		$this->redis = new Redis;
		$this->redis->connect(REDIS_HOST, REDIS_PORT);
	}
	
	public function __destruct()
	{
		$this->redis->close();
	}
	
	public function getTimestamp($query = [])
	{
		$curl = new Curl;
		$curl->setOpt(CURLOPT_SSL_VERIFYPEER,false);
		$curl->setOpt(CURLOPT_SSL_VERIFYHOST,2);
		$curl->get(BN_BASE_URL."/api/v1/time");
		if($curl->error){
			throw new Exception('Curl Error: '.__function__.' ' . $curl->errorCode . ': ' . $curl->errorMessage. "===" . json_encode($curl->response));
		}
		$result = $curl->response;
		$query['recvWindow'] = 5000;
		$query['timestamp'] = $result['serverTime'];
		$curl->close();
		unset($curl);
		return $query;
	}
	
	public function getSignature(array $query)
	{
		return hash_hmac('sha256' , urldecode(http_build_query($query)) , BN_SECKEY);
	}
	
	public function getCurl()
	{
		$curl = new Curl;
		$curl->setHeader('X-MBX-APIKEY',BN_PUBKEY);
		$curl->setOpt(CURLOPT_SSL_VERIFYPEER,false);
		$curl->setOpt(CURLOPT_SSL_VERIFYHOST,2);
		return $curl;
	}
	
	public function buy(Trade $trade)
	{
		$trade->check();
		
		$input['symbol'] = $trade->symbol;
		$input['side'] = "BUY";
		$input['type'] = "MARKET";
		$input['newOrderRespType'] = "FULL";
		$input['quantity'] = $trade->trade_qty;
		
		$result = $this->newOrder($input);
		
		//計算全部均價
		$count_up = 0;
		$count_qty = 0;
		$count_fee = 0;
		foreach($result['fills'] as $key => $value){
			$count_up += $value['price']*$value['qty'];
			$count_qty += $value['qty'];
			$count_fee += $value['commission'];
		}
		$trade->exc_b_price = number_format($count_up / $count_qty,$trade->price_len);
		$trade->exc_b_qty = $count_qty;
		$trade->exc_b_fee = number_format($count_fee , $trade->lot_len);
		$trade->order_id = $result['orderId'];
		if(!empty($trade->stop_price)){
			$trade->sl = $trade->stop_price;
		}else{
			$trade->sl = number_format($exc_price * 0.5,$trade->price_len);//保證停損係數
		}
		
		$trade->time = ceil($result['transactTime']/1000);
		$trade->date = date('Y-m-d H:i:s',ceil($result['transactTime']/1000));
		
		$this->redis->hSet(BOT_PREFIX.":TRADING",$trade->symbol,json_encode($trade));
		
		return $trade;
	}
	
	public function sell(Trade $trade)
	{
		$input['symbol'] = $trade->symbol;
		$input['side'] = "SELL";
		$input['type'] = "MARKET";
		$input['newOrderRespType'] = "FULL";
		$input['quantity'] = $trade->qty;
		
		//get this coin balance
		$target_symbol = str_replace("BTC","",$trade->symbol);
		$bal = $this->redis->hGet(BOT_PREFIX.":BALANCE",$target_symbol);
		$input2 = floor_dec($bal, $trade->lot_len);
		echo "$target_symbol: $bal : $input2".PHP_EOL;
		$input['quantity'] = $input2;
		
		
		$result = $this->newOrder($input);
		
		//計算全部均價
		$count_up = 0;
		$count_qty = 0;
		$count_fee = 0;
		foreach($result['fills'] as $key => $value){
			$count_up += $value['price'] * $value['qty'];
			$count_qty += $value['qty'];
			$count_fee += $value['commission'];
		}
		$trade->exc_s_price = number_format($count_up / $count_qty,$trade->price_len);
		$trade->exc_s_qty = $count_qty;
		$trade->exc_s_fee = number_format($count_fee , $trade->lot_len);
		$close_price = number_format($count_up / $count_qty,$trade->price_len);
		
		//計算獲利            獲利                                成本                    手續費 
		$trade->close_profit = number_format((($trade->exc_s_price * $trade->exc_s_qty) - ($trade->exc_b_price * $trade->exc_b_qty) - $trade->exc_s_fee),8);
		$trade->close_time = ceil($result['transactTime']/1000);
		$trade->close_date = date('Y-m-d H:i:s' , ceil($result['transactTime']/1000));
		
		$this->redis->hdel(BOT_PREFIX.':TRADING',$trade->symbol);
		$this->redis->lPush(BOT_PREFIX.":TRADED",json_encode($trade));
		
		
	}
	
	public function newOrder($query)
	{
		$curl = $this->getCurl();
		$query = $this->getTimestamp($query);
		$query['signature'] = $this->getSignature($query);
		
		//print_r($query);
		$curl->post(BN_BASE_URL.'/api/v3/order',$query);
		if($curl->error){
			throw new Exception('Curl Error: '.__function__.' ' . $curl->errorCode . ': ' . $curl->errorMessage. "===" . json_encode($curl->response)."===".json_encode($query));
		}
		$this->updateAccount();
		$result = $curl->response;
		$curl->close();
		unset($curl);
		return $result;
	}
	
	public function queryOrder($query)
	{
		$curl = $this->getCurl();
		$query = $this->getTimestamp($query);
		$query['signature'] = $this->getSignature($query);
		
		//print_r($query);
		$curl->get(BN_BASE_URL.'/api/v3/order',$query);
		if($curl->error){
			throw new Exception('Curl Error: '.__function__.' ' . $curl->errorCode . ': ' . $curl->errorMessage. "===" . json_encode($curl->response));
		}
		$result = $curl->response;
		$curl->close();
		unset($curl);
		return $result;
	}
	
	public function cancelOrder($query)
	{
		$curl = $this->getCurl();
		$query = $this->getTimestamp($query);
		$query['signature'] = $this->getSignature($query);
		
		$curl->delete(BN_BASE_URL."/api/v3/order" , $query);
		if($curl->error){
			throw new Exception('Curl Error: ' . $curl->errorCode . ': ' . $curl->errorMessage. "===" . json_encode($curl->response));
		}
		$result = $curl->response;
		$curl->close();
		unset($curl);
		return $result;
	}
	
	public function queryOpenOrders($query = [])
	{
		$curl = $this->getCurl();
		$query = $this->getTimestamp($query);
		$query['signature'] = $this->getSignature($query);
		
		print_r($query);
		
		$curl->get(BN_BASE_URL."/api/v3/openOrders" , $query);
		if($curl->error){
			throw new Exception('Curl Error: '.__function__.' ' . $curl->errorCode . ': ' . $curl->errorMessage. "===" . json_encode($curl->response));
		}
		$result = $curl->response;
		$curl->close();
		unset($curl);
		return $result;
	}
	
	public function queryAllOrders($query = [])
	{
		$curl = $this->getCurl();
		$query = $this->getTimestamp($query);
		$query['signature'] = $this->getSignature($query);
		
		
		$curl->get(BN_BASE_URL."/api/v3/allOrders" , $query);
		if($curl->error){
			throw new Exception('Curl Error: '.__function__.' ' . $curl->errorCode . ': ' . $curl->errorMessage. "===" . json_encode($curl->response));
		}
		$result = $curl->response;
		$curl->close();
		unset($curl);
		return $result;
	}
	
	public function queryAccount()
	{
		$curl = $this->getCurl();
		$curl->setOpt(CURLOPT_SSL_VERIFYPEER,false);
		$curl->setOpt(CURLOPT_SSL_VERIFYHOST,2);
		$query = $this->getTimestamp();
		$query['signature'] = $this->getSignature($query);
		
		$curl->get(BN_BASE_URL."/api/v3/account" , $query);
		if($curl->error){
			throw new Exception('Curl Error: '.__function__.' ' . $curl->errorCode . ': ' . $curl->errorMessage. "===" . json_encode($curl->response));
		}
		$result = $curl->response;
		$curl->close();
		unset($curl);
		return $result;
	}
	
	public function messengerFormat($symbol , $buy , $sell , $profit)
	{
		
	}
	
	public function updateAccount()
	{
		$result = $this->queryAccount();
		//print_r($result);
		
		foreach($result['balances'] as $one){
			if($one['asset'] == "BTC"){
				$this->redis->hSet(BOT_PREFIX.":ACCOUNT","btc_free",$one['free']);
				$this->redis->hSet(BOT_PREFIX.":ACCOUNT","btc_locked",$one['locked']);
				$this->redis->hSet(BOT_PREFIX.":BOT_INFO","account_updated",date('Y-m-d H:i:s'));
			}
		}
	}
	
	public function initBalance($init_balance)
	{
		$this->redis->hSet(BOT_PREFIX.":ACCOUNT","init_balance",$init_balance);
	}
	
	#Emergency Close All Trades
	public function closeAll()
	{
		
	}

}