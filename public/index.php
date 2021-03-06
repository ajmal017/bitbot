<?php
require "../autoload.php";

use model\Trade;

$redis = new Redis();
$redis->connect(REDIS_HOST, REDIS_PORT);

$tradings = $redis->hGetAll(BOT_PREFIX.":TRADING");

$trades = [];

foreach($tradings as $key => $value){
	$trades[] = new Trade(json_decode($value,true));
}

$tradeds = $redis->lRange(BOT_PREFIX.":TRADED",0,$redis->lSize(BOT_PREFIX.":TRADED"));

$sum_profit = 0;
foreach($tradeds as $key => $one){
	$tradeds[$key] = $one = json_decode($one,true);
	$sum_profit += $one['close_profit'];
}

?>



<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <title>BitBot Status Viewer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css" integrity="sha384-Gn5384xqQ1aoWXA+058RXPxPg6fy4IWvTNh0E263XmFcJlSAwiGgFAW/dAiS6JXm" crossorigin="anonymous">
	<link rel="stylesheet" href="css/bootstrap.flaty.css">
    <script>
		
    </script>
  </head>
  <body>
  	<div class="navbar navbar-expand-lg fixed-top navbar-dark bg-primary" style="">
  		<div class="container">
  			<a href="../" class="navbar-brand">BitBOT Monitor</a>
  		</div>
  	</div>
  	<div class="container" style="margin-top:90px;">
  		<div class="row">
  		<?php
  		$total_profit = 0;
  		$total_cost = 0;
  		foreach($trades as $trade){
  			
  			$total_profit += $trade->profit_btc;
  			$total_cost += $trade->cost;
  			
  			?>
  			<div class="col-md-3" style="margin-top:20px;">
  				<?php if($trade->profit_btc > 0){
  					echo '<div class="card text-white bg-success">';
  				}else{
  					echo '<div class="card text-white bg-danger">';
  				}?>
  				
  					<div class="card-header">
  						<?php echo $trade->symbol;?><span style="float:right;"><?php echo round($trade->profit_btc/$trade->cost * 100, 2)?>%</span>
  						<?php
  						if($trade->sl >= $trade->buy_price){
  							echo '<span class="badge badge-pill badge-info">Locked Safe</span>';
  						}
  						?>
  					</div>
  					<div class="card-body">
  						<ul>
  							<li>bid: <?=$trade->bid?></li>
  							<li>buy: <?=$trade->buy_price?></li>
  							<li>stop: <?=$trade->stop_price?></li>
  							<li>sl: <?=$trade->sl?></li>
  						</ul>
  					</div>
  				</div>
  			</div>
  		<?php
  		}
  		?>
  		</div>
		<div class="row"  style="margin-top:20px;">
			<div class="col-md-6">
				<div class="card border-primary mb-3">
				  <div class="card-header">統計</div>
				  <div class="card-body">
					Total Profit: <span class="badge badge-secondary"><?php echo number_format($total_profit/$total_cost*100 , 2) ?>% </span>
									
					Total Profit (BTC): <span class="badge badge-success"><?=$sum_profit?></span> 
				  </div>
				</div>
			</div>
		</div>
		<div class="row" style="margin-top:20px;">
			<div class="class-md-12">
				<table class="table">
						<?php
						
						foreach($tradeds as $one){
					
						?>
							<tr>
								<td><?=$one['close_date']?></td>
								<td><?=$one['symbol']?></td>
								<td>
								<?php
								if($one['close_profit'] < 0){
									echo "<span class='badge badge-danger'>".$one['close_profit']."</span>";	
								}else{
									echo "<span class='badge badge-success'>".$one['close_profit']."</span>";	
								}?>
								</td>
								<td><?=$one['entry_type']?></td>
							</tr>
							
						<?php }?>
				</table>
			</div>
		</div>
  	</div>
    <script src="https://code.jquery.com/jquery-3.2.1.slim.min.js" integrity="sha384-KJ3o2DKtIkvYIK3UENzmM7KCkRr/rE9/Qpg6aAZGJwFDMVNA/GpGFF93hXpG5KkN" crossorigin="anonymous"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.12.9/umd/popper.min.js" integrity="sha384-ApNbgh9B+Y1QKtv3Rn7W3mgPxhU9K/ScQsAP7hUibX39j7fakFPskvXusvfa0b4Q" crossorigin="anonymous"></script>
	<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js" integrity="sha384-JZR6Spejh4U02d8jOt6vLEHfe/JQGiRRSQQxSfFWpi1MquVdAyjUar5+76PVCmYl" crossorigin="anonymous"></script>
  </body>
</html>
