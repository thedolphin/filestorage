#!/usr/bin/perl

use Fcntl ':mode';
use File::Path;
use File::Basename 'dirname';
use LWP::UserAgent;
use Digest::SHA qw(sha256_hex);
use Data::Dumper;
use File::ExtAttr ':all';
use Time::HiRes qw ( time );

#use strict;

my $img = 'img01.wikimart.ru';

my $storage = "/vol/$img";
my $srchost = "http://$img";
my $lastfile = "$img.last";
my $listfile = "zcat $img.gz|";
my $logfile = ">$img.log";

my $hashstorage = '/vol/storage.hash';

my $skip = 0;
my $lastnum = 0;

$|++;
$< = $> = $( = $) = 80; # uid, eiud, gid, egid => www
umask(0022); # 0644

if (open(my $lh, "<$lastfile")) {
    $skip = int <$lh>;
    print "continuing from $skip\n";
    close($lh);
} else {
    print "begining\n";
}

open(my $in, $listfile) or die $!;
open(my $out, $logfile) or die $!;

my $stop = 0;

$SIG{'INT'} = sub { print "Finishing!\n"; $stop = 1; };

$time_begin = time;

while(<$in>) {
    chomp;

    break if $stop;
    next if $. <= $skip;

#    next if /\.\d{10}$/;
#    next if /meta\.json$/;

    ($d) = m|([0-9a-z]{2}/[0-9a-z]{2}/[0-9a-z]{8}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{12}\..+)|;
    next if not $d;

    $lastnum = $linenum = $.;
    $linenum =~ s/(\d{1,3}?)(?=(\d{3})+$)/$1 /g;

    print $out "[$linenum] $d";

#    if ( -s "$storage/$d" ) {
#        print ": exists, skipping\n";
#        next;
#    }

    $time_start = time;

    my $ua = LWP::UserAgent->new;
    my $req = HTTP::Request->new(GET => $srchost .'/'. $d);
    my $res = $ua->request($req);

    $time_fetch = time;

    if (!$res->is_success) {
        print $out ': error: ' . $res->code . "\n";
        next;
    }

    my $data = $res->content;

    $hash = sha256_hex($data);
    @v = $hash =~ m/^(.{2})(.{2})(.{2})/;
    $hashpath = $hashstorage .'/'. join('/', @v) .'/'. $hash;

    $time_sha = time;

    print $out " -> $hashpath: ";

    if ( -f $hashpath) {
        print $out "dup\n";
    } else {
        print $out "new\n";
        mkpath(dirname("$hashpath"));
        open($fh, ">$hashpath"); print $fh $data; close($fh);
        setfattr($hashpath, 'sha256', $hash);
    }

    $time_save = time;

    $realpath = $storage .'/'. $d;
    mkpath(dirname($realpath));

    link $hashpath, $realpath;

    $time_link = time;

    $d_fetch = $time_fetch - $time_start;
    $d_sha   = $time_sha   - $time_fetch;
    $d_save  = $time_save  - $time_sha;
    $d_link  = $time_link  - $time_save;
    $d_all   = $time_link  - $time_start;
    $sum_fetch += $d_fetch;
    $sum_sha   += $d_sha;
    $sum_save  += $d_save;
    $sum_link  += $d_link;
    $sum_all   += $d_all;
    $count++;

    $stats = sprintf("fetch: %.3f, save: %.3f, link: %.3f, total: %.3f",
        $d_fetch, $d_save, $d_link, $d_all);

    $avg_stats = sprintf("avg fetch: %.3f, avg save %.3f, avg link: %.3f, avg: %.3f, spd: %.2f/s",
        $sum_fetch / $count, $sum_save / $count, $sum_link / $count, $sum_all / $count,
        $count / ($time_link - $time_begin));

    print $out "[$linenum] $stats, $avg_stats\n";
    if ($lastnum % 100 == 0) {
        print "[$linenum] $avg_stats\n";
    }
}

if(open(my $lh, ">$lastfile")) {
    print $lh $lastnum;
    close($lh);
}

close($in);
close($out);
