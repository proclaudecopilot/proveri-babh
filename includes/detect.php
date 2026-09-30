<?php
if (!defined('ABSPATH')) exit;

/* ============ Държави ============ */
function babh6_countries() {
    static $c = null;
    if ($c === null) {
        $c = array('полша','италия','германия','австрия','чехия','словакия','унгария','румъния','гърция','турция','испания','франция','холандия','нидерландия','белгия','швейцария','великобритания','сащ','китай','индия','канада','дания','швеция','норвегия','финландия','португалия','ирландия','литва','латвия','естония','хърватия','словения','сърбия','македония','северна македония','русия','украйна','беларус','япония','корея','южна корея','бразилия','аржентина','мексико','кипър','малта','исландия','люксембург','молдова','тайван','тайланд','виетнам','сингапур','албания','босна и херцеговина','грузия','армения','казахстан','узбекистан','австралия','нова зеландия','юар','египет','израел','оае','саудитска арабия','иран','пакистан','шри ланка','чили','перу','колумбия','венецуела','уругвай','българия');
        $c = array_flip($c);
    }
    return $c;
}

function babh6_first_part($s) {
    $s = trim((string)$s);
    if ($s === '') return '';
    $parts = explode(',', $s);
    return preg_replace('/\s+/u', ' ', trim($parts[0]));
}

function babh6_is_country($s) {
    $s = trim((string)$s);
    if ($s === '' || mb_strlen($s, 'UTF-8') > 50) return false;
    $l = mb_strtolower($s, 'UTF-8');
    if (strpos($l, ',') !== false) return false;
    foreach (array('оод','еоод','еад',' ад','gmbh','ltd','inc','srl','s.a','s.r.l') as $m) {
        if (mb_strpos($l, $m) !== false) return false;
    }
    return isset(babh6_countries()[$l]);
}

function babh6_norm_firm($s) {
    $s = mb_strtolower(babh6_first_part($s), 'UTF-8');
    if ($s === '') return '';
    $s = preg_replace('/[."\'\x{201E}\x{201C}\x{00AB}\x{00BB}]/u', '', $s);
    $s = preg_replace('/\s*(еоод|ооод|оод|еад|ад|ет|gmbh|srl|inc|ltd|llc|s\.?r\.?l\.?|s\.?a\.?)\s*$/u', '', $s);
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return mb_substr($s, 0, 190, 'UTF-8');
}

/**
 * Дали фирмата е с българска регистрация и седалище. Подава се целият текст от
 * полето (име + адрес след запетаята), защото адресът е най-сигурният белег:
 * „…ЕООД, гр. София, ул. …“ е българска; „…GmbH, Германия“ / „…, Warszawa, Polska“ не е.
 *
 * Ред на проверката:
 *   1. изрично „България/Bulgaria“ в текста → българска;
 *   2. чужда правна форма, чужда държава или град (кирилица или латиница) → чужда;
 *   3. българска правна форма (ЕООД, ООД, АД…), български град/адресни съкращения → българска;
 *   4. иначе: предимно кирилица → българска.
 */
function babh6_is_bg_firm($s) {
    $s = trim((string)$s);
    if ($s === '' || mb_strlen($s, 'UTF-8') < 3) return false;
    if (babh6_is_country($s)) return false;
    $l = mb_strtolower($s, 'UTF-8');

    if (preg_match('/(^|[^\p{L}])(българия|bulgaria|bulgarien|bulgarie)([^\p{L}]|$)/u', $l)) return true;

    $bg_legal = (bool)preg_match('/(^|[^\p{L}])(еоод|оод|еад|ад|ет|сд|кд|кда|адсиц)([^\p{L}]|$)/u', $l);
    $bg_place = (bool)preg_match('/(гр\.|с\.|ул\.|бул\.|ж\.?к\.?|кв\.|пл\.|бл\.|обл\.|общ\.|община|п\.к\.)/u', $l)
        || (bool)preg_match('/(^|[^\p{L}])(софия|пловдив|варна|бургас|русе|стара загора|плевен|велико търново|благоевград|шумен|сливен|добрич|хасково|кърджали|кюстендил|ловеч|монтана|пазарджик|перник|разград|силистра|смолян|търговище|видин|враца|габрово|ямбол|асеновград|казанлък|димитровград|дупница|троян|ботевград|самоков|карлово|пещера|свищов|горна оряховица|севлиево|лом|петрич|сандански|гоце делчев|велинград|панагюрище|айтос|карнобат|несебър|поморие|созопол|провадия|девня|балчик|каварна|нова загора|чирпан|раднево|харманли|свиленград|момчилград|елхово|тутракан|исперих|попово|омуртаг|козлодуй|мездра|берковица|банкя|костинброд|елин пелин|ихтиман|сливница|своге|правец|етрополе|радомир|брезник|тетевен|луковит|червен бряг|левски|белене|павликени|бяла|разлог|банско|якоруда|sofia|plovdiv|varna|burgas|bourgas|ruse|rousse|stara zagora|pleven|veliko tarnovo|blagoevgrad|shumen|sliven|dobrich|haskovo|kardzhali|kyustendil|lovech|montana|pazardzhik|pernik|razgrad|silistra|smolyan|targovishte|vidin|vratsa|gabrovo|yambol|asenovgrad|kazanlak|dimitrovgrad|dupnitsa|troyan|botevgrad|samokov|karlovo)([^\p{L}]|$)/u', $l);

    /* Чужди правни форми (без български белег за седалище) */
    if (!$bg_legal && !$bg_place
        && preg_match('/(^|[^\p{L}])(gmbh|s\.?r\.?l\.?|s\.?p\.?a\.?|s\.?a\.?s\.?|sp\.?\s*z\s*o\.?\s*o\.?|spółka|spolka|b\.?v\.?|n\.?v\.?|kft|zrt|d\.?o\.?o\.?|a\.?s\.?|s\.?r\.?o\.?|ltd\.?|limited|inc\.?|llc|llp|corp\.?|co\.?,?\s*ltd|oyj|aktiebolag|ab|ag|sa|sarl|sas|plc|pty|oy|aps|a\/s|ug)([^\p{L}]|$)/iu', $l)) return false;
    /* Чужди държави и градове на латиница */
    if (preg_match('/(^|[^\p{L}])(germany|deutschland|poland|polska|italy|italia|france|spain|españa|espana|netherlands|nederland|holland|belgium|belgique|austria|österreich|hungary|magyarország|czech|česká|czechia|slovakia|slovensko|slovenia|slovenija|croatia|hrvatska|serbia|srbija|romania|românia|greece|hellas|turkey|türkiye|usa|u\.s\.a|united states|united kingdom|england|uk|ireland|switzerland|schweiz|suisse|sweden|sverige|denmark|danmark|norway|norge|finland|suomi|portugal|lithuania|lietuva|latvia|latvija|estonia|eesti|cyprus|malta|ukraine|russia|china|india|japan|korea|canada|australia|israel|london|berlin|münchen|munich|hamburg|paris|warszawa|warsaw|kraków|krakow|wrocław|poznań|gdańsk|łódź|opole|wien|vienna|prague|praha|brno|bratislava|budapest|amsterdam|rotterdam|brussels|bruxelles|madrid|barcelona|lisboa|lisbon|milano|milan|roma|rome|torino|bologna|athens|athina|thessaloniki|bucharest|bucurești|bucuresti|zagreb|ljubljana|beograd|belgrade|istanbul|ankara|moscow|kyiv|kiev|stockholm|copenhagen|københavn|oslo|helsinki|dublin|zürich|zurich|geneva|nicosia|limassol|new york|chicago|los angeles|florida|california|texas|nevada|utah)([^\p{L}]|$)/iu', $l)) return false;
    /* Чужди държави и градове на кирилица (без България) */
    static $foreign_cyr = null;
    if ($foreign_cyr === null) {
        $names = array_keys(babh6_countries());
        unset($names[array_search('българия', $names, true)]);
        $names = array_merge($names, array('варшава','краков','вроцлав','познан','гданск','лодз','катовице','ополе','прага','бърно','острава','братислава','кошице','виена','залцбург','грац','берлин','мюнхен','хамбург','франкфурт','кьолн','щутгарт','дюселдорф','париж','лион','марсилия','лондон','манчестър','дъблин','амстердам','ротердам','брюксел','антверпен','мадрид','барселона','валенсия','лисабон','порто','милано','рим','торино','болоня','неапол','флоренция','атина','солун','букурещ','клуж','тимишоара','яш','будапеща','дебрецен','любляна','загреб','белград','нови сад','скопие','истанбул','анкара','измир','москва','санкт петербург','киев','одеса','стокхолм','гьотеборг','копенхаген','осло','хелзинки','цюрих','женева','берн','никозия','лимасол','ню йорк','чикаго','лос анджелис','флорида','калифорния','тексас','торонто','монреал','ванкувър','сидни','мелбърн','токио','сеул','шанхай','пекин','мумбай','делхи','тел авив'));
        $foreign_cyr = $names;
    }
    foreach ($foreign_cyr as $n) {
        if (mb_strpos($l, $n) !== false && preg_match('/(^|[^\p{L}])' . preg_quote($n, '/') . '([^\p{L}]|$)/u', $l)) return false;
    }

    /* Българска правна форма или адресни белези */
    if ($bg_legal || $bg_place) return true;

    $cyr = preg_match_all('/[А-Яа-я]/u', $s);
    $lat = preg_match_all('/[A-Za-z]/', $s);
    return ($cyr > $lat && $cyr > 5);
}

/* ============ Регистрационен номер ============ */
function babh6_obl_names() {
    return array(1=>'София-град',2=>'Бургас',3=>'Варна',4=>'Пловдив',5=>'Стара Загора',6=>'Велико Търново',7=>'Русе',8=>'Плевен',9=>'Хасково',10=>'Благоевград',11=>'Шумен',12=>'Сливен',13=>'Добрич',14=>'Кърджали',15=>'Кюстендил',16=>'Ловеч',17=>'Монтана',18=>'Пазарджик',19=>'Перник',20=>'Разград',21=>'Силистра',22=>'Смолян',23=>'София-област',24=>'Търговище',25=>'Видин',26=>'Враца',27=>'Габрово',28=>'Ямбол');
}

function babh6_parse_reg($reg) {
    $reg = preg_replace('/\s+/u', '', (string)$reg);
    if ($reg === '') return null;
    $type = mb_substr($reg, 0, 1, 'UTF-8');
    $rest = mb_substr($reg, 1, null, 'UTF-8');
    if (!in_array($type, array('П','Т','P','T'), true)) return null;
    /* Реалните данни съдържат суфикси като "***" или "/1" след цифрите — приемаме ги */
    if (!preg_match('/^(\d{7,})(.*)$/u', $rest, $m)) return null;
    $digits = $m[1];
    $suffix = mb_substr(trim($m[2]), 0, 8, 'UTF-8');
    if ($type === 'P') $type = 'П';
    if ($type === 'T') $type = 'Т';
    $obl_code = intval(substr($digits, 0, 2));
    $yy = intval(substr($digits, 2, 2));
    $year = ($yy < 50) ? 2000 + $yy : 1900 + $yy;
    $obl_names = babh6_obl_names();
    return array(
        'reg'    => $type . $digits . $suffix,
        'rtype'  => $type,
        'ryear'  => $year,
        'oblast' => isset($obl_names[$obl_code]) ? $obl_names[$obl_code] : '',
    );
}

/* ============ Дати ============ */
function babh6_excel_date($num) {
    $n = floatval($num);
    if ($n < 20000 || $n > 60000) return null;
    $ts = ($n - 25569) * 86400;
    return gmdate('Y-m-d', (int)round($ts));
}

function babh6_parse_date_any($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    if (is_numeric($v)) {
        $d = babh6_excel_date($v);
        if ($d) return $d;
    }
    if (preg_match('/(\d{1,2})[.,\/](\d{1,2})[.,\/](\d{4})/u', $v, $m)) {
        $day = intval($m[1]); $mon = intval($m[2]); $yr = intval($m[3]);
        if ($mon >= 1 && $mon <= 12 && $day >= 1 && $day <= 31) {
            return sprintf('%04d-%02d-%02d', $yr, $mon, $day);
        }
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return $m[0];
    return null;
}

/* ============ Регулаторни флагове ============ */
function babh6_flag_defs() {
    static $defs = null;
    if ($defs !== null) return $defs;
    $defs = array(
        array('terms'=>array('epimedium','епимедиум','разгонен козел','horny goat','icariin','икариин'),'label'=>'Epimedium','sev'=>'high','note'=>'Novel Food неодобрен'),
        array('terms'=>array('tongkat ali','eurycoma','тонгкат али'),'label'=>'Tongkat Ali','sev'=>'high','note'=>'Novel Food неодобрен'),
        array('terms'=>array('dmaa','1,3-dimethylamylamine'),'label'=>'DMAA','sev'=>'high','note'=>'Забранен стимулант'),
        array('terms'=>array('dmha','octodrine','2-aminoisoheptane'),'label'=>'DMHA','sev'=>'high','note'=>'Забранен стимулант'),
        array('terms'=>array('ephedra','ефедра','ephedrine','ефедрин'),'label'=>'Ефедра','sev'=>'high','note'=>'Забранена'),
        array('terms'=>array('yohimbin','йохимбин','yohimbe','йохимбе'),'label'=>'Йохимбе','sev'=>'high','note'=>'Забранен в БГ'),
        array('terms'=>array('ostarine','lgd-4033','rad-140','mk-677','cardarine','sarm'),'label'=>'SARMs','sev'=>'high','note'=>'Забранени'),
        array('terms'=>array('phenibut','фенибут'),'label'=>'Фенибут','sev'=>'high','note'=>'Лекарство'),
        array('terms'=>array('sibutramin','сибутрамин'),'label'=>'Сибутрамин','sev'=>'high','note'=>'Изтеглено лекарство'),
        array('terms'=>array('sildenafil','силденафил','tadalafil','тадалафил'),'label'=>'PDE5i','sev'=>'high','note'=>'Лекарство'),
        array('terms'=>array('kratom','кратом','mitragyna'),'label'=>'Кратом','sev'=>'high','note'=>'Забранен'),
        array('terms'=>array('cannabidiol','канабидиол','cbd','cbg'),'label'=>'CBD','sev'=>'med','note'=>'Novel Food'),
        array('terms'=>array('turkesterone','туркестерон'),'label'=>'Туркестерон','sev'=>'med','note'=>'Novel Food'),
        array('terms'=>array('berberine','берберин','берберис'),'label'=>'Берберин','sev'=>'med','note'=>'Спорен — Италия ОК, Дания не'),
        array('terms'=>array('dhea','dehydroepiandrosterone','дхеа'),'label'=>'DHEA','sev'=>'high','note'=>'Стероиден хормон'),
        array('terms'=>array('melatonin','мелатонин'),'label'=>'Мелатонин','sev'=>'med','note'=>'В БГ — лекарство'),
        array('terms'=>array('nicotinamide mononucleotide','никотинамид мононуклеотид','nmn'),'label'=>'NMN','sev'=>'med','note'=>'Novel Food'),
        array('terms'=>array('ashwagandha','ашваганда','withania'),'label'=>'Ашваганда','sev'=>'med','note'=>'Под мониторинг'),
        array('terms'=>array('synephrine','синефрин'),'label'=>'Синефрин','sev'=>'low','note'=>'Лимит 30 мг/ден'),
        array('terms'=>array('red yeast rice','червен ориз','monacolin','монаколин'),'label'=>'Монаколин','sev'=>'med','note'=>'Под рестрикции'),
        array('terms'=>array('fadogia','фадогия'),'label'=>'Fadogia','sev'=>'high','note'=>'Novel Food'),
        array('terms'=>array('shilajit','шиладжит','мумио'),'label'=>'Шиладжит','sev'=>'med','note'=>'Novel Food'),
        array('terms'=>array('tudca','тауроурсодезоксихолева'),'label'=>'TUDCA','sev'=>'med','note'=>'Novel Food'),
        array('terms'=>array('pterostilbene','птеростилбен'),'label'=>'Птеростилбен','sev'=>'med','note'=>'Novel Food'),
        array('terms'=>array('huperzine','хуперзин'),'label'=>'Хуперзин A','sev'=>'med','note'=>'Novel Food'),
        array('terms'=>array('noopept','ноопепт','piracetam','пирацетам','aniracetam'),'label'=>'Рацетами','sev'=>'high','note'=>'Лекарства'),
        array('terms'=>array('kava','piper methysticum'),'label'=>'Кава','sev'=>'high','note'=>'Забранена в БГ'),
        array('terms'=>array('7-keto'),'label'=>'7-Keto DHEA','sev'=>'high','note'=>'Стероид'),
    );
    return $defs;
}

/**
 * Автоматични бележки: съвпадение по дума в име / състав / предназначение.
 * Пази намерения термин, полето и кратък откъс, за да може интерфейсът да
 * покаже КАКВО и КЪДЕ е намерено, а не регулаторна присъда.
 * @return array [{label, sev, term, field, excerpt}]
 */
function babh6_find_flags_fields($name, $composition = '', $purpose = '') {
    $fields = array('name' => (string)$name, 'composition' => (string)$composition, 'purpose' => (string)$purpose);
    $lower  = array();
    foreach ($fields as $k => $v) $lower[$k] = mb_strtolower($v, 'UTF-8');
    $out = array();
    foreach (babh6_flag_defs() as $d) {
        $hit = null;
        foreach ($fields as $k => $v) {
            if ($lower[$k] === '') continue;
            foreach ($d['terms'] as $term) {
                $pos = mb_strpos($lower[$k], $term, 0, 'UTF-8');
                if ($pos !== false) {
                    $from = max(0, $pos - 25);
                    $len  = mb_strlen($term, 'UTF-8') + 50;
                    $ex   = trim(mb_substr($fields[$k], $from, $len, 'UTF-8'));
                    if ($from > 0) $ex = '…' . $ex;
                    if ($from + $len < mb_strlen($fields[$k], 'UTF-8')) $ex .= '…';
                    $hit = array('label' => $d['label'], 'sev' => $d['sev'], 'term' => $term, 'field' => $k, 'excerpt' => $ex);
                    break 2;
                }
            }
        }
        if ($hit) $out[] = $hit;
    }
    return $out;
}

/** Обратна съвместимост: само по общ текст (без поле/откъс). */
function babh6_find_flags($text) {
    $t = mb_strtolower((string)$text, 'UTF-8');
    if ($t === '') return array();
    $out = array();
    foreach (babh6_flag_defs() as $d) {
        foreach ($d['terms'] as $term) {
            if (mb_strpos($t, $term) !== false) {
                $out[] = array('label' => $d['label'], 'sev' => $d['sev'], 'term' => $term, 'field' => '', 'excerpt' => '');
                break;
            }
        }
    }
    return $out;
}

/** Име на полето за показване. */
function babh6_field_label($field) {
    $m = array('name' => 'наименование', 'composition' => 'състав', 'purpose' => 'предназначение');
    return isset($m[$field]) ? $m[$field] : 'наличните данни';
}

/** Публично име на категория с резервен текст (кодовете не се показват). */
function babh6_category_label($code) {
    static $labels = null;
    if ($labels === null) {
        $labels = array('other' => 'Без определена категория');
        foreach (babh6_categories() as $c) $labels[$c[0]] = $c[1];
    }
    return isset($labels[$code]) ? $labels[$code] : 'Категорията не е определена';
}

/* ============ Категории ============ */
function babh6_categories() {
    static $cats = null;
    if ($cats !== null) return $cats;
    $cats = array(
        array('vitamins','Витамини',array('витамин','vitamin','b12','b6','d3','к2','niacin','folic','ниацин','фолиев','биотин','biotin','токоферол')),
        array('minerals','Минерали',array('магнезий','magnesium','цинк','zinc','калций','calcium','желязо','iron','селен','selenium','хром','chromium','йод','iodine','манган','manganese','калий','potassium','молибден','molybdenum')),
        array('protein','Протеини и спортни добавки',array('протеин','protein','whey','уей','казеин','casein','bcaa','бцаа','креатин','creatine','глутамин','glutamine','аргинин','arginin','бета-аланин','beta-alanine','изолат','isolate','гейнер','gainer','карнитин','carnitin','таурин','taurin','цитрулин','citrullin','pre-workout','workout')),
        array('collagen','Колаген и добавки за стави',array('колаген','collagen','глюкозамин','glucosamine','хондроитин','chondroitin','msm','хиалуронов','hyaluronic','стави','joint','пептид','peptid')),
        array('omega','Омега мастни киселини и масла',array('омега','omega','рибено масло','fish oil','epa','dha','krill','крил','ленено масло','flaxseed','вечерна иглика','evening primrose')),
        array('digestion','Храносмилане',array('пробиотик','probiotic','лактобацил','lactobacill','бифидобактер','bifido','ензим','enzyme','псилиум','psyllium','инулин','inulin','бромелаин','bromelain','храносмилане','чревен','intestin','колон')),
        array('immune','Имунитет',array('имунитет','immune','immun','ехинацея','echinacea','бъз','elderberry','sambucus','прополис','propolis')),
        array('herbal','Билки и растителни екстракти',array('екстракт','extract','билк','herb','куркум','curcumin','гинко','ginkgo','женшен','ginseng','ашваганда','ashwagandha','родиола','rhodiola','босвелия','boswellia','артишок','artichoke','маточина','melissa','лайка','chamomile','маслинов лист','olive leaf','силимарин','silymarin')),
        array('weight','Тегло и енергия',array('отслабване','weight','fat burner','мазнини','глюкоманан','glucomannan','зелено кафе','green coffee','гарциния','garcinia','кофеин','caffeine','термоген','thermogen','диет','diet','slim','keto','кетон')),
        array('beauty','Кожа, коса и нокти',array('кожа','skin','коса','hair','нокти','nail','beauty','красота','анти-ейдж','anti-age','anti-aging','glow')),
        array('men','За мъже',array('мъжки','male','тестостерон','testosteron','либидо','libido','трибулус','tribulus','мака','maca','потентност','potency','простат','prostat','палмето','saw palmetto')),
        array('women','За жени',array('женск','female','women','менопауза','menopause','pms','цикъл','хормонал','hormone','витекс','vitex','фолиева','бременн','pregnan')),
        array('children','За деца',array('деца','child','kids','бебе','baby','infant','педиатр','pediatr','junior','юноша','тийн','teen')),
        array('sleep','Сън и спокойствие',array('сън','sleep','мелатонин','melatonin','валериана','valerian','теанин','theanine','пасифлора','passionflower','лавандула','lavender','глицин','glycin','5-htp','тревож','стрес','stress','calm','relax')),
        array('cardio','Сърце и кръвообращение',array('сърц','heart','cardio','коензим q10','coq10','холестерол','cholesterol','кръвно налягане','blood pressure','нар ','pomegranate','хибискус','hibiscus')),
        array('detox','Черен дроб и антиоксиданти',array('детокс','detox','черен дроб','liver','бял трън','milk thistle','глутатион','glutathione','антиоксидант','antioxidant','н-ацетил','nac ')),
    );
    return $cats;
}

function babh6_categorize($name, $composition) {
    $text = mb_strtolower((string)$name . ' ' . (string)$composition, 'UTF-8');
    foreach (babh6_categories() as $cat) {
        foreach ($cat[2] as $term) {
            if (mb_strpos($text, $term) !== false) return $cat[0];
        }
    }
    return 'other';
}
