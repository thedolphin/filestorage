#!/bin/sh
File1='{ "Filename": "testfile1", "Extension": "jpeg", "UUID": "'$(uuidgen)'" }'
File2='{ "Filename": "testfile2", "Extension": "jpeg", "UUID": "'$(uuidgen)'" }'

[ -f testfile1 ] || dd if=/dev/urandom bs=1024 count=1024 > testfile1
[ -f testfile2 ] || dd if=/dev/urandom bs=1024 count=1023 > testfile2

#curl -D - -F File1="$File1" -F Data1=@testfile1 -F File2="$File2" -F Data2=@testfile2 http://img-single.lan/upload
#curl -D - -F File1="$File1" -F Data1=@testfile1 -F File2="$File2" -F Data2=@testfile2 http://127.0.0.1/upload
#curl -D - -F File1="$File1" -F Data1=@testfile1 http://127.0.0.1/upload
curl -D - -F Data1=@testfile1 -F File1="$File1" -F File2="$File2" -F Data2=@testfile2 http://127.0.0.1/upload
