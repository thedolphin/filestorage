#!/usr/bin/perl

use Fcntl ':mode';
use File::Path;
use File::Basename 'dirname';
use LWP::UserAgent;
use Digest::SHA qw(sha256_hex);
use Data::Dumper;
use File::ExtAttr ':all';

#use strict;

my $storage = '/vol/filestorage-v3';
my $hashstorage = '/vol/filestorage-v3.hash';
my $srchost = 'http://10.1.0.19';

$|++;
$< = $> = $( = $) = 80; # uid, eiud, gid, egid => www
umask(0077); # 0600

while(<>) {
    chomp;

    next if /\.\d{10}$/;
    next if /meta\.json$/;

    ($d) = m|([0-9a-z]{2}/[0-9a-z]{2}/[0-9a-z]{8}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{12}\..+)|;

    next if not $d;
    print "$d";

    if ( -s "$storage/$d" ) {
        print ": exists, skipping\n";
        next;
    }

    my $ua = LWP::UserAgent->new;
    my $req = HTTP::Request->new(GET => $srchost .'/'. $d);
    my $res = $ua->request($req);

    if (!$res->is_success) {
        print ': error: ' . $res->code . "\n";
        next;
    }

    my $data = $res->content;

    $hash = sha256_hex($data);
    @v = $hash =~ m/^(.{2})(.{2})(.{2})/;
    $hashpath = $hashstorage .'/'. join('/', @v) .'/'. $hash;

    print " -> $hashpath: ";

    if ( -f $hashpath) {
        print "dup\n";
    } else {
        print "new\n";
        mkpath(dirname("$hashpath"));
        open($fh, ">$hashpath"); print $fh $data; close($fh);
        setfattr($hashpath, 'sha256', $hash);
    }

    $realpath = $storage .'/'. $d;
    mkpath(dirname($realpath));

    link $hashpath, $realpath;
}
