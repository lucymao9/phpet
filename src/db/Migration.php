<?php

namespace phpet\db;

/**
 * 迁移基类。
 *
 * 迁移文件返回一个匿名子类实例，文件名（去掉 .php）就是迁移名：
 *
 *     return new class extends Migration {
 *         public function up(Connection $db): void { ... }
 *         public function down(Connection $db): void { ... }
 *     };
 *
 * 这里不提供 Schema 构造器：DDL 的方言差异比查询大得多，与其造一个
 * 只能覆盖简单场景的半成品，不如直接写 SQL，看得见、改得动。
 */
abstract class Migration
{
    abstract public function up(Connection $db): void;

    abstract public function down(Connection $db): void;
}
