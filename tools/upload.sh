#!/bin/bash

if [ ! -f "$1" ]; then
    echo "usage: upload.sh <filename.ext> <fs.hostname>"
    exit 1
fi

FileName=$(basename "$1")
FileExt="${FileName##*.}"
FilePrefix="${FileName%.*}"

if [ "$FilePrefix" == "$FileExt" ]; then
    echo "filename must have an extension"
    exit 1
fi

File1='{ "Filename": "'$FilePrefix'", "Extension": "'$FileExt'", "UUID": "'$(uuidgen | tr '[:upper:]' '[:lower:]')'" }'

curl -D - -F Data1=@testfile1 -F File1="$File1" "http://$2/upload"

echo -e "\nDone\n"
