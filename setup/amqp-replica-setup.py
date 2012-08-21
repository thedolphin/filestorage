#!/usr/bin/python
import pika
import ConfigParser

config = ConfigParser.RawConfigParser()
config.read('../filestorage.ini')

connectionparams = pika.ConnectionParameters(    \
    host         = config.get('amqp','host'),    \
    port         = config.getint('amqp','port'), \
    virtual_host = config.get('amqp','vhost'),   \
    credentials  = pika.PlainCredentials(        \
        config.get('amqp', 'user'),              \
        config.get('amqp', 'pass')))

connection = pika.BlockingConnection(connectionparams)
channel = connection.channel()

channel.exchange_declare(exchange = 'filestorage', type = 'headers', durable = True);

mirror = config.get('replica','rabbit')
queue_args = dict()

if mirror == 'all':
    queue_args['x-ha-policy'] = 'all'
elif mirror != 'none':
    queue_args['x-ha-policy'] = 'nodes'
    queue_args['x-ha-policy-params'] = mirror.split(',');

queuename = 'filestorage.replica.' + config.get('node', 'hostname')

channel.queue_declare(queue = queuename, durable = True, arguments = queue_args)

bind_args = dict()
bind_args['x-match'] = 'any'
bind_args['broadcast'] = 'yes'
bind_args['group' + config.get('group', 'index')] = 'yes'

for parent in config.get('replica', 'parents').split(','):
    bind_args[parent] = 'yes'

channel.queue_bind(queue = queuename, exchange = 'filestorage', arguments = bind_args)
