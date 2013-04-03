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

queuename = 'filestorage.replica.' + config.get('node', 'hostname')

channel.queue_declare(queue = queuename, durable = True)

bind_args = dict()
bind_args['x-match'] = 'any'
bind_args['broadcast'] = 'yes'

parents = config.get('replica', 'parents')

if parents != 'none':
    for parent in parents.split(','):
        bind_args[parent] = 'yes'

channel.queue_bind(queue = queuename, exchange = 'filestorage', arguments = bind_args)
