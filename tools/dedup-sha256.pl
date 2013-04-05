#!/usr/bin/perl

use Fcntl ':mode';
use File::Path;
use File::Basename 'dirname';
use Digest::file 'digest_file_hex';
use File::ExtAttr ':all';
use Data::Dumper;

$storage = '/vol/storage';
$hashstorage = '/vol/storage.sha256';

$|++;

sub timedelta {
    $timed = shift;
    $days  = int($timed / 86400);
    $hours = int(($timed % 86400) / 3600);
    $mins  = int(($timed % 3600) / 60);
    $secs  = $timed % 60;

    return sprintf("%d:%02d:%02d:%02d", $days, $hours, $mins, $secs);
}

sub gethash {
    local $fn = shift;

    if (getfattr($fn, 'md5')) {
        delfattr($fn, 'md5');
    }

    if ($hash = getfattr($fn, 'user.sha256')) {
        delfattr($fn, 'user.sha256');
        setfattr($fn, 'sha256', $hash);
        return $hash
    }

    if(!($hash = getfattr($fn, 'sha256'))) {
        $hash = digest_file_hex($fn, 'SHA-256');
    }

    return $hash;
}

sub props {
    $a = shift; $b = shift;
    return $a > $b ? int($a / $b) : '1/' . int($b / $a);
}

sub find {
    local $dir = shift;
    local $dh;
    local $d;

    local $countall = shift || 1;
    local $countlinked = shift || 0;
    local $countdups = shift || 0;
    local $countnew = shift || 0;
    local $ts = shift || time;
    local $level = shift || 0;
    local $levels = shift || [];

    print "$dir, $level\n";

    opendir($dh, $dir) || die "can't opendir: $!";

    while($d = readdir($dh)) {
        next if $d =~ /^\.\.?$/;
        next if "$dir/$d" eq '/vol/storage/temp';

        ($ino, $mode, $s) = (stat("$dir/$d"))[1,2,7];

        if (S_ISDIR($mode)) {
            @$levels[$level]++;
            ($countall, $countlinked, $countdups, $countnew) = find("$dir/$d", $countall, $countlinked, $countdups, $countnew, $ts, $level + 1, $levels);
        } elsif (S_ISREG($mode)) {

            next if not $d =~ /^[0-9a-z]{8}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{12}/;

            $td = time - $ts || 1;

            print '[spent:'   . timedelta($td) .
                  ' dirs:'    . join('.', @$levels) .
                  ' files:'   . $countall .
                  ' linked:'  . $countlinked.
                  ' dups:'    . $countdups .
                  ' new:'     . $countnew .
                  ' files/s:' . props($countall, $td).'] ';

            if ($d =~ /\.(json|countDown)$/) {
                print "$dir/$d: junk\n";
                unlink "$dir/$d";
                next;
            }

            if ($s == 0) {
                print "$dir/$d: empty\n";
                unlink "$dir/$d";
                next;
            }

            $countall++;

            ($ext) = $d =~ /.*\.([^\.]+)$/;
            $hash = gethash("$dir/$d");
            @v = $hash =~ m/^(.{2})(.{2})(.{2})/;
            $path = $hashstorage .'/'. join('/', @v) .'/'. $hash;
            print "$dir/$d: ";
            if ( -f $path) {
                ($hino, $nl) = (stat(_))[1,3];
                if ($ino == $hino) {
                     $countlinked++ if $nl > 2;
                     print "linked, $nl links\n";
                } else {
                     $countdups++;
                     print "duplicate, $nl links\n";
                     unlink "$dir/$d";
                     link $path, "$dir/$d";
                }
            } else {
                $countnew++;
                print "new\n";
                mkpath(dirname($path));
                link "$dir/$d", $path;
            }
        }
    }
    closedir $dh;

    return ($countall, $countlinked, $countdups, $countnew);
}

find($storage);
