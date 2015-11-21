# MySQL migrate environment using Yii2

It can happen that you work in development environment and you make changes to database tables structures, adding tables, or changing fields.

At start
--------

At start you have cloned production database (named ```prod```) in development database (named ```dev```). Then you have worked in development database, creating new tables, changing fields attributes, etc.etc..

Finally, immediately before we launch this code, you must have a fresh copy of production database in a file ```db_prod_backup.sql```.

How it works
------------

This code does 4 things:
 1. Restore  ```db_prod_backup.sql``` backup file in a new database (```$nameDbProd```);
 2. Append to this new database the new tables created in development environment;
 3. Apply tables structure changes made in development environment to this new database;
 

1. Restore  ```db_prod_backup.sql``` in a new database (```$nameDbDev```)
--------------------------------------------------------------------------

    private function f1_RestoreProductionSqlFile()
    {
        echo "f1_RestoreProductionSqlFile\n";
        
        echo "-> DROP&CREATE DATABASE ".$this->nameDbProd."\n";
        \Yii::$app->dbRoot->createCommand( sprintf('DROP DATABASE IF EXISTS %s; CREATE DATABASE %s;', $this->nameDbProd, $this->nameDbProd) )->execute();
        
        echo "-> Import production sql file\n";
        $cmd = 'mysql -u root -p\''.$this->rootPassword.'\' -h localhost '.$this->nameDbProd.' < '.$this->SQL_START_FILE;
        exec($cmd);
    }

This function simply drop&create the new database named ```nameDbProd```. Then it will be populated with import from a sql file, that it is last backup of production database.


2. Append to this new database the new tables created in development environment
---------------------------------------------------------------------------------

    private function f2_AppendNewTablesFromDev()
    {
        echo "f2_AppendNewTablesFromDev\n";
        
        $tablesDev = \Yii::$app->dbRoot->createCommand('SELECT table_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = "'.$this->nameDbDev.'"')->queryColumn();
        $tablesProd = \Yii::$app->dbRoot->createCommand('SELECT table_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = "'.$this->nameDbProd.'"')->queryColumn();
        $tablesDiff = array_diff($tablesDev, $tablesProd);

        foreach($tablesDiff as $t)
        {
            if(in_array($t, $tablesDev) == false) continue;
            
            echo "-> Clone table ".$t."\n";
            $sql = sprintf("CREATE TABLE IF NOT EXISTS %s.%s LIKE %s.%s", $this->nameDbProd, $t, $this->nameDbDev, $t);
            \Yii::$app->dbRoot->createCommand( $sql )->execute();
        }

    }
    
Here you compare tables list from development and final environment. For each table difference, you check if that table exists in development environment. If table does not exist in development environment (so there is some new table in production, TODO!), you skip the current table and so on.

To clone the table you use ```CREATE TABLE IF NOT EXISTS tablename LIKE tablename```


3. Apply tables structure changes made in development environment to this new database
--------------------------------------------------------------------------------------

    private function f3_ChangesStructureFromDev()
    {
        echo "f3_ChangesStructureFromDev\n";
        
        $rsColsDev = \Yii::$app->dbRoot->createCommand('SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = "'.$this->nameDbDev.'"')->queryAll();
        $rsColsProd = \Yii::$app->dbRoot->createCommand('SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = "'.$this->nameDbProd.'"')->queryAll();

        // Remove TABLE_SCHEMA key otherwise they will be all different
        $colsDev = []; foreach($rsColsDev as $rs) { unset($rs['TABLE_SCHEMA']); $colsDev[$rs['TABLE_NAME'].'.'.$rs['COLUMN_NAME']] = $rs; };
        $colsProd = []; foreach($rsColsProd as $rs) { unset($rs['TABLE_SCHEMA']); $colsProd[$rs['TABLE_NAME'].'.'.$rs['COLUMN_NAME']] = $rs; };
        
        // Check for new column in development environment
        $colsNew = array_diff_key($colsDev, $colsProd);
        foreach($colsNew as $nameCol=>$arrCol)
        {
            $null = ($colsDev[$nameCol]['IS_NULLABLE'] == 'YES')?'NULL':'NOT NULL';
            $def = ($colsDev[$nameCol]['COLUMN_DEFAULT']!=null)?'DEFAULT "'. $colsDev[$nameCol]['COLUMN_DEFAULT'].'"':'';
            $type = $colsDev[$nameCol]['COLUMN_TYPE'];
            $comment = 'COMMENT "'.addslashes($colsDev[$nameCol]['COLUMN_COMMENT']).'"';
            
            $sql = 'ALTER TABLE '.$this->nameDbProd.'.'.$colsDev[$nameCol]['TABLE_NAME'].' ADD '.$nameCol.' '.$type.' '.$null.' '.$def.' '.$comment.';';
            echo $sql."\n";
            \Yii::$app->dbRoot->createCommand($sql)->execute();
        }
        
        $colsInter = array_intersect_key($colsDev, $colsProd);
        foreach($colsInter as $nameCol=>$arrCol)
        {
            $arrDiff = array_diff_assoc($colsDev[$nameCol], $colsProd[$nameCol]);
            if(count($arrDiff) > 0)
            {
                $sql = null;
                if(array_key_exists( 'IS_NULLABLE', $arrDiff) || array_key_exists( 'COLUMN_DEFAULT' , $arrDiff) ||  array_key_exists( 'COLUMN_TYPE' , $arrDiff) ||  array_key_exists( 'COLUMN_COMMENT' , $arrDiff))
                {
                    $null = ($colsDev[$nameCol]['IS_NULLABLE'] == 'YES')?'NULL':'NOT NULL';
                    $def = ($colsDev[$nameCol]['COLUMN_DEFAULT']!=null)?'DEFAULT "'. $colsDev[$nameCol]['COLUMN_DEFAULT'].'"':'';
                    $type = $colsDev[$nameCol]['COLUMN_TYPE'];
                    $comment = 'COMMENT "'.addslashes($colsDev[$nameCol]['COLUMN_COMMENT']).'"';
                    
                    $sql = 'ALTER TABLE '.$this->nameDbProd.'.'.$colsDev[$nameCol]['TABLE_NAME'].' MODIFY '.$nameCol.' '.$type.' '.$null.' '.$def.' '.$comment.';';
                }
                else if(isset($arrDiff['ORDINAL_POSITION']))
                {
                    
                }
                else
                {
                    echo $nameCol."\n";
                    var_dump($arrDiff);                    
                }
                
                if($sql!=null) 
                {
                    echo $sql."\n";
                    \Yii::$app->dbRoot->createCommand($sql)->execute();
                }                
            }
        }
    }
    
    This block is a bit complex. To check differences in table columns, you need to have columns from both database in a comparable form. For this purpose, you will have two arrays (```$colsDev``` and ```$colsDev```), both with tablename.columnname as key and complete structure of columns info as value. It is important to remove TABLE_SCHEMA attribute otherwise when you will check differences, all columns will be diffent (because TABLE_SCHEMA are different for all columns).
    
    
Using ```array_diff_key``` (applied to array keys, tablename.columnname), you check if there are new columns. Then you have only to apply ALTER TABLE ... to create the new column in production environment.

Finally we need to check if there are changes in column definition. In this case you firstly take the common columns from each tables of the two database (with ```array_intersect_key```) and then you check if there are different fields in column definition (with ```array_diff_assoc```). 

In this moment I check only differences in IS_NULLABLE, COLUMN_DEFAULT, COLUMN_TYPE and COLUMN_COMMENT properties of each column definition. If there are other different properties, these are only printed out.

LAUNCH THE ACTION
------------------

To launch the Yii2 action, put the file ```DatabaseMigrationController.php``` in console/controllers folder and then from the root folder of Yii2 installation launch

    ./yii database-migration/launch

TODO
------------

Check if table columns or tables are removed from production environment.
