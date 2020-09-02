<?php
    /**
	* Remote KYC Abandon - A utility to signal when users have abandoned the Remote-KYC process.
	*
	* @author    James Lawson
	* @copyright 2019 IGM www.intergreatme.com
	* @note      This program is distributed in the hope that it will be useful - WITHOUT
	* ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
	* FITNESS FOR A PARTICULAR PURPOSE.
    */

    // this is just a simple test where we log the HTTP GET when items are abandoned.
    file_put_contents('log.txt', strftime('%Y-%m-%d %T').' '.print_r($_GET, true).PHP_EOL, FILE_APPEND);
?>