#!/usr/bin/php 
<?php

defined('VERSION') || define('VERSION', '0.3 beta');

Log::start();

# set these in file REQUIRED
$database_options = array(
    'host'      => 'localhost',
    'user'      => 'user',
    'pass'      => 'pass',
    'db'        => 'rgb',
    'table'     => 'colors',
);

# set these in cli
$commandline_options = array(    
    'filename'  => false,
    'output'    => 'allrgb.png',
    'pngcrush'  => false,
    'regen'     => false
);

$dir = getcwd();

(defined('STDIN'))          || Log::error('Please run from the commandline');
(extension_loaded('gd'))    || Log::error('GD Library is required for this script');
(extension_loaded('mysql')) || Log::error('MySQL is required for this script');

# command line arguments.

array_shift($argv);

if(!count($argv)){ Log::help(); }

foreach($argv as $k => $a){
    switch($a){
        case '-f':
            $commandline_options['filename'] = realpath($argv[$k + 1]);
            break;
        case '-o':
            $commandline_options['output']   = realpath($argv[$k + 1]);
            $path = explode('/', $commandline_options['output']);
            array_pop($path);
            $path = implode('/', $path);
            if(!is_writable($path)){
                Log::error('Insufficient Directory Permissions: Output File Not Writable');
            }
            break;
        case '-c':
            $commandline_options['pngcrush'] = true;
            break;
        case '-db':
            $commandline_options['regen']    = true;
            break;
        case '-h':
        case '--help':
            Log::help();
            break;
        default:
            break;
    }
}

# set options

$options  = array_merge($database_options, $commandline_options);

#################################################################################################################

# class

class AllRgb{
    
    private $link;  # db link
    private $db;    # db name
    private $o;     # options

    public function __construct($options){
        if(!file_exists($options['filename'])){
            Log::error("File does not exist - {$options['filename']}");
        }
        list($w, $h) = getimagesize($options['filename']);
        if($w !== 4096 && $h !== 4096){
            Log::error('Only supports 4096x4096 original image');
        }

        Log::msg('Beginning @ '.date('g:i:s a'));
        $this->o = $options;
        # connect to Database
        $this->mysqlConnect();
        # check database
        $this->checkDB();
        # check if a regen command
        if($options['regen']){
            $this->reGenerateColors();
            Log::msg('Colors finished', true);
            die();
        }
        # run
        $this->process();
        # after
        if($this->o['pngcrush'] && file_exists($this->o['output'])){
            $this->crush();
        }
        $this->reGenerateColors();
    }
    
    # processing

    private function process(){
        Log::msg("Processing Image", true);
        $src_file = $this->o['filename'];
        $src_mime = mime_content_type($src_file);
        switch($src_mime){
            case 'image/jpeg' : 
                $imagecreatefrom = 'imagecreatefromjpeg';
                break;
            case 'image/png' : 
                $imagecreatefrom = 'imagecreatefrompng';
                break;
            default:
                Log::error("Unsupported file type {$src_mime}");
                break;
        }
        $src   = $imagecreatefrom($src_file);
        (is_resource($src)) || Log::error("Not Valid Resource from Image.");
        $dest  = imagecreatetruecolor(4096, 4096);
        for($y = 0;$y < 4096;$y+=2){
            for($x = 0;$x < 4096;$x+=2){ 
                $this->setPixel($src, $dest, $x, $y); 
            }
        }
        Log::msg('1/4 passes finished '.date('g:i:s a'), true); 
        for($y = 1;$y < 4096;$y+=2){
            for($x = 1;$x < 4096;$x+=2){ 
                $this->setPixel($src, $dest, $x, $y); 
            }
        }
        Log::msg('2/4 passes finished '.date('g:i:s a'), true); 
        for($y = 0;$y < 4096;$y+=2){
            for($x = 1;$x < 4096;$x+=2){ 
                $this->setPixel($src, $dest, $x, $y); 
            }
        }
        Log::msg('3/4 passes finished '.date('g:i:s a'), true); 
        for($y = 1;$y < 4096;$y+=2){
            for($x = 0;$x < 4096;$x+=2){ 
                $this->setPixel($src, $dest, $x, $y); 
            }
        }
        Log::msg('4/4 passes finished '.date('g:i:s a'), true); 
        # write image
        imagepng($dest, $this->o['output'], 9);
        imagedestroy($src);
        imagedestroy($dest);
        Log::msg("Image complete");
        Log::sep(2);
    }
    
    # Database stuff
    
    private function mysqlConnect(){
        $this->link = mysql_connect($this->o['host'], $this->o['user'], $this->o['pass']);
        if(!$this->link){ Log::error('db no connect'); }
        $this->db = mysql_select_db($this->o['db'], $this->link);
        if(!$this->db){ Log::error('no can select database'); }
        Log::msg('DB Connected');
    }
    
    private function query($query){
        $result = mysql_query($query);
        $return = array();
        while($row = mysql_fetch_object($result)){ $return[] = $row; }
        mysql_free_result($result);
        return $return;
    }
    
    private function insert($r, $g, $b, $lum){
        mysql_query("INSERT INTO {$this->o['table']} (r,g,b,lum) VALUES ({$red}, {$green}, {$blue}, {$lum})");
    }
    
    private function optimizeTable(){
        Log::msg("Optimizing Table", true);
        mysql_query("OPTIMIZE TABLE {$this->o['table']}");
        Log::msg("DONE");
    }

    private function checkDB(){
        # check if database is full and reload if nec.
        $tables = mysql_list_tables($this->o['db']);
        $db = 'Tables_in_'.$this->o['db'];
        $has_colors = false;
        $has_backup = false;
        while($row = mysql_fetch_object($tables)){ 
            $has_backup = ($row->$db == 'backup') ? true : $has_backup; 
            $has_colors = ($row->$db == $this->o['table']) ? true : $has_colors; 
        }
        if($has_colors){
    		$colors = $this->checkColors();
            if($colors < 16777216){
                ($has_backup) ? $this->reGenerateColors() : $this->generateColors();
            }
        }else{
            $this->generateColors();
        }
    }
    
    private function fetchCol($query){
        $result = $this->query($query);
        if(isset($result[0])){
            foreach($result[0] as $key => $value){ 
                return $value; 
            }
        }
        return false;
    }
    
    private function rm($id){
        if(!$id){ return false; }
        mysql_query("DELETE FROM {$this->o['table']} WHERE id = {$id} LIMIT 1");
    }
    
    # color stuff
    
    private function rgb2lum($r,$g,$b){
        return round(($r * 0.3) + ($g * 0.59) + ($b * 0.11));
    }

    private function getClosest($lum){
        $result = $this->query("SELECT id,lum,ABS(lum - {$lum}) AS distance,r,g,b FROM ((SELECT id,r,g,b,lum FROM `{$this->o['table']}` WHERE lum >= {$lum} ORDER BY lum LIMIT 1) UNION ALL (SELECT id,r,g,b,lum FROM `{$this->o['table']}` WHERE lum < {$lum} ORDER BY lum DESC LIMIT 1)) AS n ORDER BY distance LIMIT 1");
        if(is_array($result) && is_object($result[0])){
            return $result[0];
        }
        return false;
    }

    private function setPixel($src, $dest, $x, $y){
        $src_color = imagecolorat($src, $x, $y);
        $color     = imagecolorsforindex($src, $src_color);
        $lum       = $this->rgb2lum($color['red'],$color['green'],$color['blue']);
        $closest   = $this->getClosest($lum);
        $new_color = imagecolorallocate($dest, $closest->r, $closest->g, $closest->b);
        $set       = imagesetpixel($dest, $x, $y, $new_color);
        if($set){ 
            $this->rm($closest->id); 
        }
    }
    
    # generating colors
    
    private function generateColors(){
    	$colors = $this->checkColors();
    	if($colors == 16777216){
    		Log::msg('Database OK', true);
    		return true;
    	} 
        Log::msg('Generate Color Table', true);
        # drop existing table
        mysql_query("DROP TABLE IF EXISTS {$this->o['table']}");
        Log::msg('Creating table');
        # create new table
        mysql_query("CREATE TABLE {$this->o['table']} (
            id serial,
            r int(3) not null default 0,
            g int(3) not null default 0,
            b int(3) not null default 0,
            lum int(3) not null default 0
        )");
        Log::msg('Generating colors');
        # fill it up
        $red = 0;
        $green = 0;
        $blue = 0;
        $total = 0;
        while($red < 256){
            $green = 0;
            $blue = 0;
            while($green < 256){
                $blue = 0;
                while($blue < 256){
                    $lum = $this->rgb2lum($red, $green, $blue);
                    $this->insert($red, $green, $blue, $lum);
                    $blue++;
                    $total++;
                    if($total > 1 && ($total % 1000000) == 0){
                        Log::msg("$total colors made.");
                    }
                }
                $green++;
            }
            $red++;
        }
        # make index on lum column
        Log::msg("{$total} colors made.");
        Log::msg('Creating index');
        mysql_query("CREATE INDEX allrgblum ON {$this->o['table']}(lum)");
        Log::msg('Creating backup');
        mysql_query("CREATE TABLE backup SELECT * FROM {$this->o['table']}");
        # optimize table, not sure if necessary here
        $this->optimizeTable();
        Log::msg('Done generating colors table');
    }
    
    private function reGenerateColors(){
    	$colors = $this->checkColors();
    	if($colors == 16777216){
    		Log::msg('Database OK', true);
    		return true;
    	}
        Log::msg("Regenerate Color Table", true);
        mysql_query("TRUNCATE {$this->o['table']}");
        Log::msg("Inserting Values");
        mysql_query("insert into {$this->o['table']} select id,r,g,b,lum from backup");
        $this->optimizeTable();
        Log::msg('Done generating colors table');
        Log::sep(2);
    }
    
    private function checkColors(){
        return $this->fetchCol("SELECT COUNT(*) FROM {$this->o['table']} LIMIT 1");
    }
    
    # pngcrush
    
    private function crush(){
        Log::msg('pngcrushing', true);
        system("pngcrush -brute {$this->p['output']} pngcrush_{$this->p['output']}");
        Log::msg('pngcrush finished');
    }

}

#################################################################################################################

# output to terminal

class Log{
    public static function msg($msg, $separator = false){
        echo "{$msg}\n";
        (!$separator) || self::sep();
    }
    public static function sep($number = 1){
        for($i = 0;$i < $number;$i++){
            echo "•••••••••••••••••••••••••••••••••••••••••••••••••••••••••••••••••••••••••••••\n";
        }
    }
    public static function error($msg){
        self::msg("FATAL ERROR: {$msg}", true);
        die();
    }
    public static function start(){
        echo "\nAllRgb\n#############################################################################\nVersion ".VERSION."                           by Greg Russell • www.grgrssll.com\n\n";
    }
    public static function help(){
        self::msg('Help Menu', true);
        echo "-f [filename]....Input Filename
-o [filename]....Output Filename. If not set will use allrgb.png as filename.
-c...............Run pngcrush on output file. 
                 Ouputs second file prepended with pngcrush_
                 Requires pngcrush to be installed on system.
-db..............Regenerate Database and exit. 
                 This is done automatically after each image.\n\n";
        self::msg('Example', true);
        echo "$ php allrgb.php -f image.png -o allrgb.png -c\n\n\n";
        die();
    }
}

#################################################################################################################

$rgb = new AllRgb($options);

#################################################################################################################

?>