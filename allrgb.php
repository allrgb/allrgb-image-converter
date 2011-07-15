#!/usr/bin/php 
<?php

defined('VERSION') || define('VERSION', '0.3 beta');

$dir = getcwd();

Log::start();

# set these in file REQUIRED
$database_options = array(
    'host'      => 'localhost',
    'user'      => 'username',
    'pass'      => 'password',
    'db'        => 'rgb',
    'table'     => 'colors',
);

# set these in cli
$commandline_options = array(    
    'filename'  => false,
    'output'    => $dir.'/allrgb.png',
    'pngcrush'  => false,
    'regen'     => false,
    'dithering' => 2
);

(defined('STDIN'))          || Log::error('Please run from the commandline');
(extension_loaded('gd'))    || Log::error('GD Library is required for this script');
(extension_loaded('mysql')) || Log::error('MySQL is required for this script');

# command line arguments.

array_shift($argv);

if(!count($argv)){ Log::help(); }

foreach($argv as $k => $a){
    switch($a){
        case '-f':
            $commandline_options['filename']  = realpath($dir.'/'.$argv[$k + 1]);
            break;
        case '-o':
            $commandline_options['output']    = $dir.'/'.$argv[$k + 1];
            break;
        case '-d':
            $commandline_options['dithering'] = intval($argv[$k + 1]);
            break;
        case '-c':
            $commandline_options['pngcrush']  = true;
            break;
        case '-db':
            $commandline_options['regen']     = true;
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
    
    private $link;      # db link
    private $db;        # db name
    private $o;         # options

    public function __construct($options){
        # set options
        $this->o = $options;
        # connect to Database
        $this->mysqlConnect();
        # check if a regen command
        if($options['regen']){
            $this->checkDB();
            die();
        }
        # check input file
        if(!$this->o['filename'] || !file_exists($this->o['filename'])){
            Log::error("File does not exist - {$this->o['filename']}");
        }
        list($w, $h) = getimagesize($this->o['filename']);
        if($w !== 4096 && $h !== 4096){
            Log::error('Only supports 4096x4096 input image');
        }
        # check output file
        $path    = explode('/', $this->o['output']);
        $outfile = array_pop($path);
        $path    = realpath(implode('/', $path));
        if(!is_writable($path)){
            Log::error('Insufficient Directory Permissions: Output File Not Writable');
        }
        $this->o['output'] = $path.'/'.$outfile;
        # all good - start
        Log::msg('Beginning @ '.date('g:i:s a'));
        # check database
        $this->checkDB();
        # run
        $this->o['dithering'] = ($this->o['dithering'] >= 0 && $this->o['dithering'] < 4) ? $this->o['dithering'] : 1;
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
        $output   = $this->o['output'];
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
        switch($this->o['dithering']){
            case '0' :
                for($y = 0;$y < 4096;$y++){ 
                    for($x = 0;$x < 4096;$x++){ $this->setPixel($src, $dest, $x, $y); } 
                    if($y % 1024 == 0){ 
                        $p = floor(($y / 4096) * 100);
                        Log::msg($p.'% done '.date('g:i:s a'), true); 
                    }
                }
                Log::msg('1/1 pass finished '.date('g:i:s a'), true);
                break;
            case '3' :
                for($y = 0;$y < 4096;$y+=4){ for($x = 0;$x < 4096;$x+=4){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('1/16 passes finished '.date('g:i:s a'), true); 
                for($y = 1;$y < 4096;$y+=4){ for($x = 1;$x < 4096;$x+=4){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('2/16 passes finished '.date('g:i:s a'), true); 
                for($y = 2;$y < 4096;$y+=4){ for($x = 2;$x < 4096;$x+=4){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('3/16 passes finished '.date('g:i:s a'), true); 
                for($y = 3;$y < 4096;$y+=4){ for($x = 3;$x < 4096;$x+=4){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('4/16 passes finished '.date('g:i:s a'), true); 
                for($y = 0;$y < 4096;$y+=4){ for($x = 1;$x < 4096;$x+=4){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('5/16 passes finished '.date('g:i:s a'), true); 
                for($y = 1;$y < 4096;$y+=4){ for($x = 2;$x < 4096;$x+=4){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('6/16 passes finished '.date('g:i:s a'), true); 
                for($y = 2;$y < 4096;$y+=4){ for($x = 3;$x < 4096;$x+=4){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('7/16 passes finished '.date('g:i:s a'), true); 
                for($y = 3;$y < 4096;$y+=4){ for($x = 0;$x < 4096;$x+=4){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('8/16 passes finished '.date('g:i:s a'), true); 
                for($y = 0;$y < 4096;$y+=4){ for($x = 2;$x < 4096;$x+=4){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('9/16 passes finished '.date('g:i:s a'), true); 
                for($y = 1;$y < 4096;$y+=4){ for($x = 3;$x < 4096;$x+=4){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('10/16 passes finished '.date('g:i:s a'), true); 
                for($y = 2;$y < 4096;$y+=4){ for($x = 0;$x < 4096;$x+=4){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('11/16 passes finished '.date('g:i:s a'), true); 
                for($y = 3;$y < 4096;$y+=4){ for($x = 1;$x < 4096;$x+=4){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('12/16 passes finished '.date('g:i:s a'), true); 
                for($y = 0;$y < 4096;$y+=4){ for($x = 3;$x < 4096;$x+=4){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('13/16 passes finished '.date('g:i:s a'), true); 
                for($y = 1;$y < 4096;$y+=4){ for($x = 0;$x < 4096;$x+=4){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('14/16 passes finished '.date('g:i:s a'), true); 
                for($y = 2;$y < 4096;$y+=4){ for($x = 1;$x < 4096;$x+=4){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('15/16 passes finished '.date('g:i:s a'), true); 
                for($y = 3;$y < 4096;$y+=4){ for($x = 2;$x < 4096;$x+=4){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('16/16 passes finished '.date('g:i:s a'), true); 
                break;
            case '2' :
                for($y = 0;$y < 4096;$y+=3){ for($x = 0;$x < 4096;$x+=3){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('1/9 passes finished '.date('g:i:s a'), true); 
                for($y = 1;$y < 4096;$y+=3){ for($x = 1;$x < 4096;$x+=3){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('2/9 passes finished '.date('g:i:s a'), true); 
                for($y = 2;$y < 4096;$y+=3){ for($x = 2;$x < 4096;$x+=3){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('3/9 passes finished '.date('g:i:s a'), true); 
                for($y = 0;$y < 4096;$y+=3){ for($x = 1;$x < 4096;$x+=3){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('4/9 passes finished '.date('g:i:s a'), true); 
                for($y = 1;$y < 4096;$y+=3){ for($x = 2;$x < 4096;$x+=3){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('5/9 passes finished '.date('g:i:s a'), true); 
                for($y = 2;$y < 4096;$y+=3){ for($x = 0;$x < 4096;$x+=3){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('6/9 passes finished '.date('g:i:s a'), true); 
                for($y = 0;$y < 4096;$y+=3){ for($x = 2;$x < 4096;$x+=3){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('7/9 passes finished '.date('g:i:s a'), true); 
                for($y = 1;$y < 4096;$y+=3){ for($x = 0;$x < 4096;$x+=3){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('8/9 passes finished '.date('g:i:s a'), true); 
                for($y = 2;$y < 4096;$y+=3){ for($x = 1;$x < 4096;$x+=3){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('9/9 passes finished '.date('g:i:s a'), true); 
                break;
            default:
            case '1' :
                for($y = 0;$y < 4096;$y+=2){ for($x = 0;$x < 4096;$x+=2){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('1/4 passes finished '.date('g:i:s a'), true); 
                for($y = 1;$y < 4096;$y+=2){ for($x = 1;$x < 4096;$x+=2){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('2/4 passes finished '.date('g:i:s a'), true); 
                for($y = 0;$y < 4096;$y+=2){ for($x = 1;$x < 4096;$x+=2){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('3/4 passes finished '.date('g:i:s a'), true); 
                for($y = 1;$y < 4096;$y+=2){ for($x = 0;$x < 4096;$x+=2){ $this->setPixel($src, $dest, $x, $y); } }
                Log::msg('4/4 passes finished '.date('g:i:s a'), true); 
                break;
        }
        # write image
        imagepng($dest, $output, 9);
        imagedestroy($src);
        imagedestroy($dest);
        Log::msg("Image complete");
        Log::sep(2);
    }
    
    # Database stuff
    
    private function mysqlConnect(){
        # connect to db
        $this->link = @mysql_connect($this->o['host'], $this->o['user'], $this->o['pass']);
        if(!$this->link){ Log::error('db no connect'); }
        $this->db = @mysql_select_db($this->o['db'], $this->link);
        if(!$this->db){ Log::error('no can select database'); }
        Log::msg('DB Connected');
    }
    
    private function query($query){
        # query db
        $result = @mysql_query($query);
        $return = array();
        while($row = @mysql_fetch_object($result)){ $return[] = $row; }
        @mysql_free_result($result);
        return $return;
    }
    
    private function insert($r, $g, $b, $lum){
        # insert into db
        @mysql_query("INSERT INTO {$this->o['table']} (r,g,b,lum) VALUES ({$r}, {$g}, {$b}, {$lum})");
    }
    
    private function optimizeTable(){
        # optomize table... necessary?
        Log::msg("Optimizing Table", true);
        @mysql_query("OPTIMIZE TABLE {$this->o['table']}");
    }

    private function checkDB(){
        # check if database is full and reload if nec.
        Log::msg('Checking Database', true);
        $tables = mysql_list_tables($this->o['db']);
        $db = 'Tables_in_'.$this->o['db'];
        $has_colors = false;
        $has_backup = false;
        while($row = @mysql_fetch_object($tables)){ 
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
        $colors = $this->checkColors();
        Log::msg('Checking Database finished - '.$colors.' colors', true);
    }
    
    private function fetchCol($query){
        # get column
        $result = $this->query($query);
        if(isset($result[0])){
            foreach($result[0] as $key => $value){ 
                return $value; 
            }
        }
        return false;
    }
    
    private function rm($id){
        # delete
        if(!$id){ return false; }
        mysql_query("DELETE FROM {$this->o['table']} WHERE id = {$id} LIMIT 1");
    }
    
    # color stuff
    
    private function rgb2lum($r,$g,$b){
        # get rgb's lum value
        return round(($r * 0.3) + ($g * 0.59) + ($b * 0.11));
    }

    private function getClosest($lum){
        # get closest lum pixel not used yet
        $result = $this->query("SELECT id,lum,ABS(lum - {$lum}) AS distance,r,g,b FROM ((SELECT id,r,g,b,lum FROM `{$this->o['table']}` WHERE lum >= {$lum} ORDER BY lum LIMIT 1) UNION ALL (SELECT id,r,g,b,lum FROM `{$this->o['table']}` WHERE lum < {$lum} ORDER BY lum DESC LIMIT 1)) AS n ORDER BY distance LIMIT 1");
        if(is_array($result) && is_object($result[0])){
            return $result[0];
        }
        return false;
    }

    private function setPixel($src, $dest, $x, $y){
        # set pixel in image
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
        # returns number of unused colors
        return $this->fetchCol("SELECT COUNT(*) FROM {$this->o['table']} LIMIT 1");
    }
    
    # pngcrush
    
    private function crush(){
        # crush the image
        Log::msg('pngcrushing', true);
        $path = explode('/', $this->o['output']);
        $filename = array_pop($path);
        $path = implode('/', $path);
        $pngcrush_output = $path.'/pngcrush_'.$filename;
        @system("pngcrush -brute -text b \"Software\" \"Made by allrgb.php - greg russell\" {$this->o['output']} {$pngcrush_output}");
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
        self::msg("FATAL ERROR: {$msg}");
        for($i = 0;$i < 6;$i++){
            echo "☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ ☠ \n";
        }
        die();
    }
    public static function start(){
        echo "\nallrgb.php\n#############################################################################\nVersion ".VERSION."                           by Greg Russell • www.grgrssll.com\n\n";
    }
    public static function help(){
        self::msg('Help Menu', true);
        echo "-f [filename]....Input Filename jpeg or png.
-o [filename]....Output Filename. If not set will use allrgb.png as filename.
-d [n]...........Dithering. default: 2  available options: 0|1|2|3
-c...............Run pngcrush on output file. 
                 Ouputs second file prepended with pngcrush_
                 Requires pngcrush to be installed on system.
-db..............Regenerate Database and exit. 
                 This is done automatically after each image.\n\n";
        self::msg('Example', true);
        echo "$ php allrgb.php -f image.png -o allrgb.png -c -d 2
$ php allrgb.php -f image.png -o allrgb.png -c
$ php allrgb.php -f image.png -d 1 -c
$ php allrgb.php -f image.png -d 0
$ php allrgb.php -f image.png
$ php allrgb.php -db \n\n";
        self::msg('Requirements', true);
        echo "PHP 5.2+
PHP CLI
GD Library
MySQL 5+
Database in options must already exist
Don't forget to set the database options in the script file!\n\n\n";
        die();
    }
}

#################################################################################################################

$rgb = new AllRgb($options);

#################################################################################################################

?>
