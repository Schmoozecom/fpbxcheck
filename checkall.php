<?php

echo "Starting integrity check...\n";

$c = new GetConf();

$gpg = new GPG($c);

$gpg->trustFreePBX();

// Steal GetConf's DB connection
$db = $c->db;

// Grab all our modules.

$allmods = $db->query('select * from `modules`')->fetchAll();

$goodmods = 0;
$badmods = 0;
$othermods = 0;
$exploited = false;
$admin = false;
$quarantine = sys_get_temp_dir()."/freepbx_quarantine";

if (!file_exists($quarantine)) {
	mkdir($quarantine);
}

print "Checking Framework for a valid signature...\n";
$sig = $c->get('AMPWEBROOT')."/admin/modules/framework/module.sig";
if (!file_exists($sig)) {
	print "Framework is missing it's sig file, Attempting to upgrade Framework\n";
	system($c->get('AMPBIN')."/module_admin -f --no-warnings update framework");
	print "Finished upgrading Framework!! Checking for signature again\n";
	if (!file_exists($sig)) {
		print "ERROR! Framework isn't signed. Can't continue.\n";
		exit(-1);
	}
}
if (!$gpg->verifyFile($sig)) {
	print "ERROR! Framework signature file altered.\n\tYOU MAY HAVE BEEN HACKED.\n";
	if($clean) {
		print "Framework has been tampered with, upgrading Framework\n";
		system($c->get('AMPBIN')."/module_admin -f --no-warnings update framework");
		print "Finished upgrading Framework!!\n";
	} else {
		print "Please run with the --clean command\n";
		exit(-1);
	}
} else {
	print "Framework appears to be good\n";
}

if(!$clean && file_exists($c->get('AMPWEBROOT')."/admin/bootstrap.inc.php")) {
	$exploited = true;
	print "*** Exploit 'mgknight' Detected, Please run with the --clean option! *** \n";
}

if($clean) {
	print "Cleaning up exploit 'mgknight'\n";
	if (file_exists($c->get('AMPWEBROOT')."/admin/bootstrap.inc.php")) {
		$redownload = true;
		print "\tRemoving invalid bootstrap file\n";
		unlink($c->get('AMPWEBROOT')."/admin/bootstrap.inc.php");
	}

	$sql = "DELETE FROM ampusers WHERE username = 'mgknight'";
	$db->query($sql);
	print "\tDeleting 'mgknight' user, if exists..\n";

	$admins = $db->query('SELECT * FROM `ampusers` WHERE `sections` = "*"')->fetchAll();
	if(count($admins) < 1) {
		print "\tNo Admin Users detected. Adding one now.\n";
		$pass = openssl_random_pseudo_bytes(32);
		$sha1 = sha1($pass);
		$sql = "INSERT INTO ampusers (`username`, `password_sha1`, `sections`) VALUES ('admin','".$sha1."','*')";
		$db->query($sql);
		$admin['pass'] = $pass;
	}

	print "\tPurging PHP Session storage\n";
	foreach(glob(session_save_path()."/sess_*") as $session) {
		if(!unlink($session)) {
			print "\t*** UNABLE TO PURGE SESSIONS IN ".session_save_path()."\n";
		}
	}
	print "\tDone\n";

	$files = array("manager_custom.conf", "sip_custom.conf","extensions_custom.conf");
	foreach($files as $file) {
		if(file_exists($c->get('ASTETCDIR')."/".$file)) {
			print "\tMoving potentially compromised file ".$c->get('ASTETCDIR')."/".$file." to ".$quarantine."/".$file."\n";
			copy($c->get('ASTETCDIR')."/".$file,$quarantine."/".$file);
			unlink($c->get('ASTETCDIR')."/".$file);
			touch($c->get('ASTETCDIR')."/".$file);
		}
	}
	print "Cleaned potential 'mgknight' exploit. Please check your system for any suspicious activity. This script might not have removed it all!\n";
}

print "Checking FreePBX ARI Framework\n";
$fw_ari_path = $c->get('AMPWEBROOT')."/recordings/includes";
if(file_exists($fw_ari_path)) {
	exec("grep -R 'unserialize' ".$fw_ari_path, $o, $r);
	if(empty($r)) {
		print "\t*** FREEPBX ARI IS VULNERABLE ON THIS SYSTEM ***\n";
		if($clean) {
			print "\tARI IS VULNERABLE, MOVIING TO ".$c->get('AMPWEBROOT')."/recordings ".$quarantine."/fw_ari\n";
			system("cp -R ".$c->get('AMPWEBROOT')."/recordings ".$quarantine."/fw_ari");
			system("rm -Rf ".$c->get('AMPWEBROOT')."/recordings/*");
		}
	}
}
$fw_ari = $db->query("SELECT * FROM modules WHERE modulename = 'fw_ari' and enabled = 1")->fetchAll();
if(!empty($fw_ari)) {
	print "\tFreePBX ARI Framework detected as installed, attempting to update\n";
	system($c->get('AMPBIN')."/module_admin -f --no-warnings update fw_ari");
} else {
	//ari is disabled but check and remove the directory as well
	if(file_exists($c->get('AMPWEBROOT')."/recordings/index.php")) {
		$contents = file_get_contents($c->get('AMPWEBROOT')."/recordings/index.php");
		if(!preg_match("/Location:(.*)ucp/i",$contents)) {
			print "\tFreePBX ARI Framework is uninstalled but the folder exists, removing it\n";
			system("rm -Rf ".$c->get('AMPWEBROOT')."/recordings");
		}
	}
}
print "Finished with FreePBX ARI Framework\n";

$out = $gpg->checkSig($sig);
print "Now Verifying all FreePBX Framework Files\n";
$status = checkFramework($out['hashes'],$c);
if(!$status && $clean) {
	print "Framework has been tampered with, upgrading Framework\n";
	system($c->get('AMPBIN')."/module_admin -f --no-warnings update framework");
	print "Finished upgrading Framework!!\n";
} elseif(!$status && !$clean) {
	print "Framework has been tampered with\n";
	print "Please run with the --clean command\n";
	exit(-1);

}
print "Checked all FreePBX Framework Files\n";

print "Now checking all modules\n";

foreach ($allmods as $modarr) {
	$mod = $modarr['modulename'];
	if ($mod == 'admindashboard') {
		print "\t*** YOU MAY HAVE BEEN HACKED ***\n";
		print "\tThe known-bad module 'admindashboard' is present on this machine\n";
		$exploited = true;
		if(!$clean) {
			print "Please run with the --clean command\n";
			exit(-1);
		} else {
			if(file_exists($c->get('AMPWEBROOT')."/admin/modules/admindashboard")) {
				system("rm -Rf ".$c->get('AMPWEBROOT')."/admin/modules/admindashboard");
				system("amportal a ma delete $mod");
				if(file_exists(sys_get_temp_dir()."/c2.pl")) {
					unlink(sys_get_temp_dir()."/c2.pl");
				}
				if(file_exists(sys_get_temp_dir()."/c.sh")) {
					unlink(sys_get_temp_dir()."/c.sh");
				}
			}
		}
	}
	$sig = $c->get('AMPWEBROOT')."/admin/modules/$mod/module.sig";
	if (!file_exists($sig) && $redownload) {
		print "UNSIGNED MODULE $mod -- attempting to redownload\n";
		system($c->get('AMPBIN')."/module_admin -f --no-warnings update ".$mod);
	}
	if (!file_exists($sig)) {
		print "UNSIGNED MODULE $mod: This module isn't signed. It may be altered, and should be re-downloaded immediately.\n";
		print "You may add the paramater --redownload to automatically download all unsigned modules\n";

		$othermods++;
		if ($mod == "framework") {
			print "Criticial module unsigned, can't proceed. Sorry. Please upgrade manually\n";
			exit(-1);
		}
		continue;
	}

	// Now, we're checking a module. Skip the two annoying ones.
	if ($mod == "framework" || $mod == "fw_ari") {
		continue;
	}
	if (!$gpg->verifyFile($sig)) {
		print "*** YOU MAY HAVE BEEN HACKED ***\n";
		print "The signature file $sig has been altered, or, is unable to validate!\n";
		print "Re-download that module. Aborting!\n";
		exit(-1);
	}
	$sig = $gpg->verifyModule($mod);
	if ($sig['status'] == 129) {
		$goodmods++;
	} else {
		print "WARNING: Module $mod has issues. Run script again with that module name as the param\n";
		$badmods++;
	}

}

print "Complete. Summary:\n\tGood modules: $goodmods\n\tBad modules: $badmods\n\tSignature Missing: $othermods\n";
if($exploited) {
	print "**** SYSTEM WAS EXPLOITED ****\n";
}
print "Re-run this script with -m <rawmodname> for further information\nExample: -m ucp\n";
if($admin !== false) {
	print "Added new admin user called 'admin' with password '".$admin['pass']."'";
}
exit;
