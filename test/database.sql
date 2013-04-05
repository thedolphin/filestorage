select hex(`uuid`), `date`, hex(`hash`), `group`, `deleted`, `linked`, `attribute`, `value` from files join attributes using (`uuid`);
select hex(`uuid`), `date`, hex(`hash`), `group`, `deleted`, `linked` from files;
select hex(`uuid`), `attribute`, `value` from attributes;
