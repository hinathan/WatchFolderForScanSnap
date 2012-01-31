#!/usr/bin/php
<?php
/*
 WatchFolderForScanSnap - a utility for automating my own
 Scan-to-OCR-to-Dropbox workflow using a Fujitsu ScanSnap
 scanner and a headless mac (I use a Mini under my desk)

 Original Author: Nathan Schmidt <nschmidt@gmail.com>
 GPL License, share and enjoy.
*/


$basepath = '/Users/' . $_ENV["USER"];
$destfolder = $basepath . "/Dropbox/Organized/";
$CONF = array(
	'watchfolder'=>$basepath . '/Pictures/Raw\ Scans',
	'destfolder'=>$destfolder,
	'unfiledfolder'=>$destfolder . "/Unfiled/",
	'idle_first'=>4,
	'idle_between'=>5,
	'pdftotext'=>dirname(__FILE__) . '/vendor/pdftotext',
	'rules_file'=>dirname(__FILE__) . '/rules.txt',
	);

mylog("Starting up","Starting up");

while(1) {
	$files = glob($CONF['watchfolder'] . "/*.pdf");
	$ct = count($files);
	if($ct) {
		mylog("$ct documents waiting","$ct documents");
	}
	clearstatcache(true);
	foreach($files as $file) {
		if((time() - filemtime($file)) < $CONF['idle_first']) {
			mylog("Waiting for $file","Waiting");
			continue;
		}
		$arg = escapeshellarg($file);
		$gres = trim(`grep -a -c 'ABBYY FineReader' $arg`);
		if($gres === "1") {
			mylog(basename($file) . " filing document");
			stashFileProperly($file);
		} else {
			mylog(basename($file) . " running OCR","Starting OCR");
			`open -W -n -a 'Scan to Searchable PDF.app' $arg`;
			mylog(basename($file) . " complete OCR","OCR Complete");
			stashFileProperly($file);
		}
	 }
	 sleep($CONF['idle_between']);
}

function mylog($str,$say=false) {
	if($lfh = fopen(dirname(__FILE__) . "/log.txt",'a')) {
		fwrite($lfh,date('Y-m-d H:i:s') . " $str\n");
		fclose($lfh);
	}
	if($say) {
		$esc = escapeshellarg($say);
		`say $esc`;
	}
}

function stashFileProperly($file) {
	global $CONF;
	//reread, may have updated, cheap
	$rules = array();
	$rf = fopen($CONF['rules_file'],'r');
	while($row = fgetcsv($rf)) {
		$rule = array_shift($row);
		$rules[] = array($rule=>$row);
	}
	fclose($rf);

	$ptt = $CONF['pdftotext'];
	$arg = escapeshellarg($file);
	$text = `$ptt -q $arg - `;
	$target = getTargetForText($text,$rules);
	
	$summary = `$ptt $arg -|osascript -e 'set stdin to do shell script "cat"' -e "set stdout to summarize stdin in 1"|cut -d' ' -f1-10`;
	mylog(basename($file) . " summary: " . trim($summary));
	
	if($target) {
		$folder = $CONF['destfolder'] . "/" . $target . "/";
		mylog("categorized: $target","Filed under $target");
	} else {
		$folder = $CONF['unfiledfolder'];
	}

	$final = $folder . basename($file);
	
	mylog("destination is $final");
	
	if(!is_dir($folder)) {
		mylog("destination folder does not exist");
		mkdir($folder,0777,true);
	}
	if(!is_writable($folder)) {
		mylog("destination folder cannot be written");
		return;
	}

	mylog("moving $file to $final");
	rename($file,$final);
}

function getTargetForText($text,$rules) {
	foreach($rules as $rule) {
		foreach($rule as $dest=>$rows) {
			foreach($rows as $row) {
				if(!strlen($row)) {
					continue;
				}
				if(preg_match("/" . $row . "/i",$text)) {
					return $dest;
				}
			}
		}
	}
	return false;
}
