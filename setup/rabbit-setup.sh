#!/bin/sh

rabbitmqctl add_user admin admin
rabbitmqctl set_user_tags admin administrator
rabbitmqctl set_permissions admin '.*' '.*' '.*'

rabbitmqctl add_user fsuser fspass

rabbitmqctl add_vhost /filestorage
rabbitmqctl set_permissions -p /filestorage admin '.*' '.*' '.*'
rabbitmqctl set_permissions -p /filestorage fsuser '.*' '.*' '.*'
