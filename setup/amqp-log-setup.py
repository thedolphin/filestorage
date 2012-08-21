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

queuename1 = 'filestorage.dbwriter'
queuename2 = 'filestorage.logwriter'

channel.queue_declare(queue = queuename1, durable = True, arguments = queue_args)
channel.queue_declare(queue = queuename2, durable = True, arguments = queue_args)

bind_args = dict()
bind_args['x-match'] = 'any'
bind_args['broadcast'] = 'yes'
bind_args['log'] = 'yes'

channel.queue_bind(queue = queuename1, exchange = 'filestorage', arguments = bind_args)
channel.queue_bind(queue = queuename2, exchange = 'filestorage', arguments = bind_args)
