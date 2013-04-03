#!/bin/sh

echo delete
rm -rf /vol/storage
rm -rf /vol/storage.hash
rm -rf /vol/storage.temp
echo create
for l1 in {0..9}; do 
    for l2 in {0..9}; do
        for l3 in {0..9}; do
            eval $(printf 'mkdir -p /vol/storage.temp/%1d/%01d/%01d' $l1 $l2 $l3);
        done
    done
done

install -o www -g www -d /vol/storage
install -o www -g www -d /vol/storage.hash

chown -R www:www /vol/storage.temp
