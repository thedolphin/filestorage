#!/usr/bin/perl


use Fcntl ':mode';
#use File::Path;
use File::Path;
use File::Basename 'dirname';
use LWP::UserAgent;
use Digest::SHA qw(sha256_hex);
use Data::Dumper;
use File::ExtAttr ':all';

#use strict;

my $storage = '/vol/storage';
my $hashstorage = '/vol/storage.sha256';

$|++;

sub gethash {
    local $fn = shift;
    $hash = getfattr($fn, 'user.sha256');
    if ( ! $hash ) {
        $hash = digest_file_hex($fn, 'SHA-256');
        setfattr($fn, 'user.sha256', $hash);
    }
    return $hash;
}

while(<>) {
    chomp;

    next if /\.\d{10}$/;
    next if /meta\.json$/;

    ($d) = m|([0-9a-z]{2}/[0-9a-z]{2}/[0-9a-z]{8}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{12}\..+)|;
    print "$d";

    next if not $d;

    if ( -s "$storage/$d" ) {
        print ": exists, skipping\n";
        next;
    }

    my $ua = LWP::UserAgent->new;
    my $req = HTTP::Request->new(GET => 'http://10.1.0.23/' . $d);
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
        setfattr($hashpath, 'user.sha256', $hash);
    }

    $realpath = $storage .'/'. $d;
#    if ( -f $realpath ) {
#        unlink $realpath;
#    } else {
        mkpath(dirname($realpath));
#    }

    link $hashpath, $realpath;
}
