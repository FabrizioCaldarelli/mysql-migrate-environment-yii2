<?php 

namespace console\controllers;

/**
 * DatabaseMigrationController
 */
class DatabaseMigrationController extends \yii\console\Controller
{
    private $SQL_START_FILE = 'db_prod_backup.sql';
    private $nameDbDev = 'db_dev';
    private $nameDbProd = 'db_prod';
    private $rootPassword = 'root_password';
    
    
    private function f1_RestoreProductionSqlFile()
    {
        echo "f1_RestoreProductionSqlFile\n";
        
        echo "-> DROP&CREATE DATABASE ".$this->nameDbProd."\n";
        \Yii::$app->dbRoot->createCommand( sprintf('DROP DATABASE IF EXISTS %s; CREATE DATABASE %s;', $this->nameDbProd, $this->nameDbProd) )->execute();
        
        echo "-> Import production sql file\n";
        $cmd = 'mysql -u root -p\''.$this->rootPassword.'\' -h localhost '.$this->nameDbProd.' < '.$this->SQL_START_FILE;
        exec($cmd);
    }

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
    
    public function actionLaunch() {
        $this->f1_RestoreProductionSqlFile(); 
        $this->f2_AppendNewTablesFromDev();
        $this->f3_ChangesStructureFromDev();
        
        echo "Done!\n";
    }
}