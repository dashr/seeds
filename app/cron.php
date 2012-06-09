<?php
#
#	cronjob: check/update every 3 hours 
#   0 */3 * * * /usr/local/bin/php ~/cron.php >> /dev/null
#

include_once '../app/lib.php';
set_time_limit(0);


foreach ($farmers as $farmer)
{
	$f = new Seeds_Feed( $farmer );
	$f->process();
}
