#!/bin/sh

#82ef733f-1ea9-4e97-a4a2-cea14e51f77e.jpeg 

File1='{ "UUID": "a622f4a7-4b91-4fd1-b9c1-626b1650444a", "Extension": "jpeg" }'
File2='{ "UUID": "69b19e0f-f09a-4bf9-ba19-4f13a5a909ed", "Extension": "jpeg" }'

curl -D - -F File1="$File1" -F File2="$File2" http://127.0.0.1/delete
