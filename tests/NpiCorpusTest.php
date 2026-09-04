<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Real provider names sampled from the public NPPES/NPI registry, selected to
 * span credential, suffix, prefix, comma, particle, hyphen, apostrophe, and
 * middle-name forms. Locks first/last extraction on genuine clinician names.
 *
 * Comparison is exact-case: the expectations record the parser's canonical
 * title-case (NPPES stores names upper-case), so casing regressions fail.
 */
class NpiCorpusTest extends TestCase
{
    /**
     * @return array<int, array{string, string, string}>
     */
    public static function provider(): array
    {
        return [
            // apostrophe (expectations are the parser's canonical title-case,
            // not the raw NPPES casing: the fold after the apostrophe is exact)
            ['Taylor D\'hedouville', 'Taylor', 'D\'Hedouville'],
            ['O\'brien, Christopher', 'Christopher', 'O\'Brien'],
            ['Philip O\'brate', 'Philip', 'O\'Brate'],
            ['Dalys O\'connor', 'Dalys', 'O\'Connor'],
            ['Sean P. O\'connor', 'Sean', 'O\'Connor'],
            ['Jeffrey O\'dell', 'Jeffrey', 'O\'Dell'],
            ['Brittany O\'brien', 'Brittany', 'O\'Brien'],
            ['Raymond E O\'keefe', 'Raymond', 'O\'Keefe'],
            ['Lee, Na\'tavia', 'Na\'Tavia', 'Lee'],
            ['Joshua Jon D\'agostino', 'Joshua', 'D\'Agostino'],
            ['O\'connor, Kelly', 'Kelly', 'O\'Connor'],
            ['Samantha O\'neil', 'Samantha', 'O\'Neil'],
            ['Brian O\'connor', 'Brian', 'O\'Connor'],
            ['Kelson, E\'shayla MHA', 'E\'Shayla', 'Kelson'],
            ['N\'keyma Lee', 'N\'Keyma', 'Lee'],
            ['Danielle O\'connell', 'Danielle', 'O\'Connell'],
            ['April O\'neil', 'April', 'O\'Neil'],
            ['D\'addario, Dawn', 'Dawn', 'D\'Addario'],
            // comma
            ['Hahn, Victoria', 'Victoria', 'Hahn'],
            ['Soucier, Richard', 'Richard', 'Soucier'],
            ['Ronnermann, Drew', 'Drew', 'Ronnermann'],
            ['Kahoro, Joseph', 'Joseph', 'Kahoro'],
            ['Goff, Chassidy', 'Chassidy', 'Goff'],
            ['Parker, Robert', 'Robert', 'Parker'],
            ['Chiang, Jenna', 'Jenna', 'Chiang'],
            ['Sutro, Margaret', 'Margaret', 'Sutro'],
            ['Adler, Aaron', 'Aaron', 'Adler'],
            ['Shaw, Petra', 'Petra', 'Shaw'],
            ['Seifi, Sanaz', 'Sanaz', 'Seifi'],
            ['Brophy, Karyn', 'Karyn', 'Brophy'],
            ['Wheeler, Karen', 'Karen', 'Wheeler'],
            ['Oelfke, Gregory', 'Gregory', 'Oelfke'],
            ['Paul, Briauna', 'Briauna', 'Paul'],
            ['Small, David', 'David', 'Small'],
            ['Stasiuk, Christina', 'Christina', 'Stasiuk'],
            ['Banner, David', 'David', 'Banner'],
            ['Washington, Sheila', 'Sheila', 'Washington'],
            ['Miller, Jennifer', 'Jennifer', 'Miller'],
            ['Kim, Joseph', 'Joseph', 'Kim'],
            ['Echelmeyer, Meaghan', 'Meaghan', 'Echelmeyer'],
            // credential
            ['Griffiths, Veronica RN', 'Veronica', 'Griffiths'],
            ['Dr. Lana  Wahid, M.D.', 'Lana', 'Wahid'],
            ['E Lawrence, RPH', 'E', 'Lawrence'],
            ['Allen, Dehazard BCBA', 'Dehazard', 'Allen'],
            ['Peterson, April SLP', 'April', 'Peterson'],
            ['Ramos, Sharlyn PHARMD', 'Sharlyn', 'Ramos'],
            ['Herzog, Kirk PA', 'Kirk', 'Herzog'],
            ['Zborowski, Michael PH.D.', 'Michael', 'Zborowski'],
            ['Allison Edwards, L.C.S.W.', 'Allison', 'Edwards'],
            ['King, Michelle JD, LPC', 'Michelle', 'King'],
            ['Saavedra, Alicia MSW', 'Alicia', 'Saavedra'],
            ['Sekhon, Shobha M.D.', 'Shobha', 'Sekhon'],
            ['Knauer, Joshua RPH', 'Joshua', 'Knauer'],
            ['Shannon, Lori CRNA', 'Lori', 'Shannon'],
            ['Long, Michele M.D.', 'Michele', 'Long'],
            ['Freeman, Faith CPM LM', 'Faith', 'Freeman'],
            ['Christopher Cooper, D.O.', 'Christopher', 'Cooper'],
            ['Gunawardana, Rajah MD', 'Rajah', 'Gunawardana'],
            ['Cockrum, Alicia PSYD', 'Alicia', 'Cockrum'],
            ['George Nasser, M.D.', 'George', 'Nasser'],
            ['Thompson, Ramie MS', 'Ramie', 'Thompson'],
            ['Aruna Mani, M.D.', 'Aruna', 'Mani'],
            ['Juszczyk, Rona CRNA', 'Rona', 'Juszczyk'],
            ['Tokareva, Anna MSED', 'Anna', 'Tokareva'],
            ['Awuor, Victor DO', 'Victor', 'Awuor'],
            ['Peretz, Clara LMSW', 'Clara', 'Peretz'],
            ['Barry Stein, M.D.', 'Barry', 'Stein'],
            ['Graham, Michelle CFNP', 'Michelle', 'Graham'],
            ['Ogbonna, Oliver LCSW', 'Oliver', 'Ogbonna'],
            ['Patel, Vinodbhai RPH', 'Vinodbhai', 'Patel'],
            // hyphen
            ['Zenaida Viri-Schaller', 'Zenaida', 'Viri-Schaller'],
            ['Temihya Walker-Parson', 'Temihya', 'Walker-Parson'],
            ['Shelly Skjolaas-Lindell', 'Shelly', 'Skjolaas-Lindell'],
            ['Samantha Buery-Joyner', 'Samantha', 'Buery-Joyner'],
            ['Karen Phillips-Hugine', 'Karen', 'Phillips-Hugine'],
            ['Raper, Haley LCSW-A', 'Haley', 'Raper'],
            ['Mirsadies Raber-Dunning', 'Mirsadies', 'Raber-Dunning'],
            ['Ethel A. Higgins-Harris', 'Ethel', 'Higgins-Harris'],
            ['Mrs. Cheryl Blackmon-Thorne', 'Cheryl', 'Blackmon-Thorne'],
            ['Guzman, Vanessa M.A., CCC- SLP', 'Vanessa', 'Guzman'],
            ['Sarah L. Duffy-Smith', 'Sarah', 'Duffy-Smith'],
            ['Abdul-Rahman Fadi Diab', 'Abdul-Rahman', 'Diab'],
            ['Dr. Kerri-Anne Vlaming', 'Kerri-Anne', 'Vlaming'],
            ['Andrea Burnett-Sircy', 'Andrea', 'Burnett-Sircy'],
            ['Olga Iukalo-Tokarski', 'Olga', 'Iukalo-Tokarski'],
            ['Rheana Wade-Macios', 'Rheana', 'Wade-Macios'],
            ['Zeena Abdul-Kafor', 'Zeena', 'Abdul-Kafor'],
            ['Martinez-Nava, Diana', 'Diana', 'Martinez-Nava'],
            // middle
            ['Shawanda L Johnson', 'Shawanda', 'Johnson'],
            ['Douglas W. Perkins', 'Douglas', 'Perkins'],
            ['Demarco I. Jones', 'Demarco', 'Jones'],
            ['Nicole N. Mccoy', 'Nicole', 'Mccoy'],
            ['Ruth N. Waithaka', 'Ruth', 'Waithaka'],
            ['Nicole L. Colaw', 'Nicole', 'Colaw'],
            ['Victoria Blanton Eich', 'Victoria', 'Eich'],
            ['Wusthania Fondoit Alexandre', 'Wusthania', 'Alexandre'],
            ['Charles A Harris', 'Charles', 'Harris'],
            ['Jessica L Phares', 'Jessica', 'Phares'],
            ['Sofia Isabel Padilla', 'Sofia', 'Padilla'],
            ['Chad Taylor Hott', 'Chad', 'Hott'],
            ['Alexander M Arroyo', 'Alexander', 'Arroyo'],
            ['Jimmie G. Riffle', 'Jimmie', 'Riffle'],
            ['Asher Abraham Edwards', 'Asher', 'Edwards'],
            ['Artayvia C. Dunlap', 'Artayvia', 'Dunlap'],
            ['Andrew T. Farriell', 'Andrew', 'Farriell'],
            ['Ghousia Jabeen Pasha', 'Ghousia', 'Pasha'],
            ['Laurie B Sanders', 'Laurie', 'Sanders'],
            ['Jeremy M. Morris', 'Jeremy', 'Morris'],
            // particle
            ['Vance J Van Tassell', 'Vance', 'van Tassell'],
            ['Elizabeth De La Torre', 'Elizabeth', 'de la Torre'],
            ['Theresa Di Forti', 'Theresa', 'di Forti'],
            ['Mila Le', 'Mila', 'Le'],
            ['Thuy Le', 'Thuy', 'Le'],
            ['Le, Elizabeth PHARMD', 'Elizabeth', 'Le'],
            ['Britt De Blonde', 'Britt', 'de Blonde'],
            ['Angelica De Rodriguez', 'Angelica', 'de Rodriguez'],
            ['Michelle De La Guardia', 'Michelle', 'de la Guardia'],
            ['Kevin Le', 'Kevin', 'Le'],
            ['Stacy Van Heeswyk', 'Stacy', 'van Heeswyk'],
            ['Le, Isabella', 'Isabella', 'Le'],
            ['Srijisnu De', 'Srijisnu', 'De'],
            ['Mac, Ryan', 'Ryan', 'Mac'],
            ['Vivian Le', 'Vivian', 'Le'],
            ['Khuong Le', 'Khuong', 'Le'],
            ['Jaimee De Pompeo', 'Jaimee', 'de Pompeo'],
            ['Primrose Del Rosario', 'Primrose', 'del Rosario'],
            ['Le, Catherine', 'Catherine', 'Le'],
            ['Jennifer Chen Wu', 'Jennifer', 'Chen Wu'],
            ['James Grant Allman Ii', 'James', 'Allman Ii'],
            ['Susan Von Rosk', 'Susan', 'von Rosk'],
            ['Beatriz Del Villar', 'Beatriz', 'del Villar'],
            ['Tatyana Der', 'Tatyana', 'Der'],
            // plain
            ['Andrew Bonin', 'Andrew', 'Bonin'],
            ['David Jaller', 'David', 'Jaller'],
            ['Kristi Frese', 'Kristi', 'Frese'],
            ['Russell Mascarenhas', 'Russell', 'Mascarenhas'],
            ['Jazmine Briones', 'Jazmine', 'Briones'],
            ['Hailee Warapius', 'Hailee', 'Warapius'],
            ['Kora Schibner', 'Kora', 'Schibner'],
            ['Laura Moraitis', 'Laura', 'Moraitis'],
            ['Ryan Schallon', 'Ryan', 'Schallon'],
            ['Paul Gearhart', 'Paul', 'Gearhart'],
            ['W. Gentry', 'W.', 'Gentry'],
            ['Yesenia Sianez', 'Yesenia', 'Sianez'],
            ['Stella Diai', 'Stella', 'Diai'],
            ['Alicia Silvers', 'Alicia', 'Silvers'],
            ['Tracy Askew', 'Tracy', 'Askew'],
            ['Chad Johnson', 'Chad', 'Johnson'],
            // prefix
            ['Dr. Robert Graessle', 'Robert', 'Graessle'],
            ['Miss Crystal Guerrero', 'Crystal', 'Guerrero'],
            ['Mr. John Baldelli', 'John', 'Baldelli'],
            ['Dr. Mukesh Sarna', 'Mukesh', 'Sarna'],
            ['Dr. Rosalinda Taymor', 'Rosalinda', 'Taymor'],
            ['Dr. Tory Mcjunkin', 'Tory', 'Mcjunkin'],
            ['Mrs. Donna Lyons', 'Donna', 'Lyons'],
            ['Dr. Kreangkai Tyree', 'Kreangkai', 'Tyree'],
            ['Dr. Sukriti Singhal', 'Sukriti', 'Singhal'],
            ['Dr. Michael Finch', 'Michael', 'Finch'],
            ['Dr. Andre Culpepper', 'Andre', 'Culpepper'],
            ['Dr. Kaden Ridley', 'Kaden', 'Ridley'],
            ['Dr. Abhijit Patel', 'Abhijit', 'Patel'],
            ['Mr. Michael Sutherland', 'Michael', 'Sutherland'],
            ['Dr. James Campbell', 'James', 'Campbell'],
            ['Dr. Myron Pulier', 'Myron', 'Pulier'],
            ['Dr. Ursula Nawab', 'Ursula', 'Nawab'],
            ['Miss Emily Jefferys', 'Emily', 'Jefferys'],
            // suffix
            ['Robert Naples JR.', 'Robert', 'Naples'],
            ['James Pridgen III', 'James', 'Pridgen'],
            ['Ruben Meza JR.', 'Ruben', 'Meza'],
            ['Gerald Orlando II', 'Gerald', 'Orlando'],
            ['Joan Kramzer II', 'Joan', 'Kramzer'],
            ['Charles Redmond JR.', 'Charles', 'Redmond'],
            ['Roy Kelly JR.', 'Roy', 'Kelly'],
            ['Richard Greene JR.', 'Richard', 'Greene'],
            ['Dennis Cody JR.', 'Dennis', 'Cody'],
            ['Efelomo Abraham I', 'Efelomo', 'Abraham'],
            ['Richard Paoletti JR.', 'Richard', 'Paoletti'],
            ['Henry Frierson JR.', 'Henry', 'Frierson'],
            ['Gary Johnson JR.', 'Gary', 'Johnson'],
            ['Harry Hinch JR.', 'Harry', 'Hinch'],
        ];
    }

    #[DataProvider('provider')]
    public function testExtractsFirstAndLast(string $input, string $first, string $last): void
    {
        $name = (new Parser())->parse($input);

        // exact-case: the provider expectations are the parser's canonical
        // title-case (apostrophe fold, lower-cased particles), so a casing
        // regression fails here instead of hiding behind a case fold.
        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function credentialTailProvider(): array
    {
        return [
            'RN' => ['Griffiths, Veronica RN', 'RN'],
            'JD and LPC' => ['King, Michelle JD, LPC', 'JD LPC'],
            'DO' => ['Awuor, Victor DO', 'DO'],
            'LCSW' => ['Ogbonna, Oliver LCSW', 'LCSW'],
            'punctuated MD' => ['Dr. Lana  Wahid, M.D.', 'MD'],
            'RPh initial' => ['E Lawrence, RPH', 'RPh'],
            'BCBA' => ['Allen, Dehazard BCBA', 'BCBA'],
            'SLP' => ['Peterson, April SLP', 'SLP'],
            'PharmD' => ['Ramos, Sharlyn PHARMD', 'PharmD'],
            'PA' => ['Herzog, Kirk PA', 'PA'],
            'punctuated PhD' => ['Zborowski, Michael PH.D.', 'PhD'],
            'punctuated LCSW' => ['Allison Edwards, L.C.S.W.', 'LCSW'],
            'MSW' => ['Saavedra, Alicia MSW', 'MSW'],
            'second punctuated MD' => ['Sekhon, Shobha M.D.', 'MD'],
            'RPh' => ['Knauer, Joshua RPH', 'RPh'],
            'CRNA' => ['Shannon, Lori CRNA', 'CRNA'],
            'third punctuated MD' => ['Long, Michele M.D.', 'MD'],
            'punctuated DO' => ['Christopher Cooper, D.O.', 'DO'],
            'plain MD' => ['Gunawardana, Rajah MD', 'MD'],
            'PsyD' => ['Cockrum, Alicia PSYD', 'PsyD'],
            'western punctuated MD' => ['George Nasser, M.D.', 'MD'],
            'MS' => ['Thompson, Ramie MS', 'MS'],
            'second western punctuated MD' => ['Aruna Mani, M.D.', 'MD'],
            'second CRNA' => ['Juszczyk, Rona CRNA', 'CRNA'],
            'LMSW' => ['Peretz, Clara LMSW', 'LMSW'],
            'third western punctuated MD' => ['Barry Stein, M.D.', 'MD'],
            'second RPh' => ['Patel, Vinodbhai RPH', 'RPh'],
            'MA and CCC-SLP' => ['Guzman, Vanessa M.A., CCC- SLP', 'MA CCC- SLP'],
            'second PharmD' => ['Le, Elizabeth PHARMD', 'PharmD'],
            'Jr' => ['Robert Naples JR.', 'Jr'],
            'III' => ['James Pridgen III', 'III'],
            'second Jr' => ['Ruben Meza JR.', 'Jr'],
            'II' => ['Gerald Orlando II', 'II'],
            'second II' => ['Joan Kramzer II', 'II'],
            'third Jr' => ['Charles Redmond JR.', 'Jr'],
            'fourth Jr' => ['Roy Kelly JR.', 'Jr'],
            'fifth Jr' => ['Richard Greene JR.', 'Jr'],
            'sixth Jr' => ['Dennis Cody JR.', 'Jr'],
            'I' => ['Efelomo Abraham I', 'I'],
            'seventh Jr' => ['Richard Paoletti JR.', 'Jr'],
            'eighth Jr' => ['Henry Frierson JR.', 'Jr'],
            'ninth Jr' => ['Gary Johnson JR.', 'Jr'],
            'tenth Jr' => ['Harry Hinch JR.', 'Jr'],
        ];
    }

    #[DataProvider('credentialTailProvider')]
    public function testCredentialRowsDoNotLeakCredentialsIntoGivenNameFields(string $input, string $suffix): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($suffix, $name->getSuffix(), "suffix for '$input'");
        $this->assertSame('', $name->getInitials(), "initials for '$input'");
        $this->assertSame('', $name->getMiddlename(), "middle name for '$input'");
    }

    /**
     * Non-credential corpus rows must not grow a suffix, and their middle /
     * initial placement is pinned: single letters stay initials, real middle
     * tokens stay middlenames, everything else stays out of both getters.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function nonCredentialFieldProvider(): array
    {
        return [
            // input, expected middlename, expected initials
            'apostrophe surname' => ["Taylor D'hedouville", '', ''],
            'initial before apostrophe surname' => ["Sean P. O'connor", '', 'P.'],
            'comma form' => ['Hahn, Victoria', '', ''],
            'hyphenated surname' => ['Zenaida Viri-Schaller', '', ''],
            'hyphenated given with salutation' => ['Dr. Kerri-Anne Vlaming', '', ''],
            'single-letter middle is an initial' => ['Shawanda L Johnson', '', 'L'],
            'dotted initial' => ['Douglas W. Perkins', '', 'W.'],
            'real middle token' => ['Victoria Blanton Eich', 'Blanton', ''],
            'particle surname with initial' => ['Vance J Van Tassell', '', 'J'],
            'plain' => ['Andrew Bonin', '', ''],
            'dotted single-token given' => ['W. Gentry', '', ''],
            'salutation with plain name' => ['Dr. Robert Graessle', '', ''],
            'compound hyphenated surname' => ['Mrs. Cheryl Blackmon-Thorne', '', ''],
            'dotted initial before hyphenated surname' => ['Ethel A. Higgins-Harris', '', 'A.'],
            'middle token before generational suffix word' => ['James Grant Allman Ii', 'Grant', ''],
            'compound surname' => ['Jennifer Chen Wu', '', ''],
        ];
    }

    #[DataProvider('nonCredentialFieldProvider')]
    public function testNonCredentialRowsLockSuffixMiddleAndInitials(string $input, string $middle, string $initials): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame('', $name->getSuffix(), "suffix for '$input'");
        $this->assertSame($middle, $name->getMiddlename(), "middle name for '$input'");
        $this->assertSame($initials, $name->getInitials(), "initials for '$input'");
    }
}
