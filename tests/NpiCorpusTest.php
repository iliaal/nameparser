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
 * Comparison is case-insensitive: NPPES stores names upper-case and the parser
 * title-cases its output.
 */
class NpiCorpusTest extends TestCase
{
    /**
     * @return array<int, array{string, string, string}>
     */
    public static function provider(): array
    {
        return [
            // apostrophe
            ['Taylor D\'hedouville', 'Taylor', 'D\'hedouville'],
            ['O\'brien, Christopher', 'Christopher', 'O\'brien'],
            ['Philip O\'brate', 'Philip', 'O\'brate'],
            ['Dalys O\'connor', 'Dalys', 'O\'connor'],
            ['Sean P. O\'connor', 'Sean', 'O\'connor'],
            ['Jeffrey O\'dell', 'Jeffrey', 'O\'dell'],
            ['Brittany O\'brien', 'Brittany', 'O\'brien'],
            ['Raymond E O\'keefe', 'Raymond', 'O\'keefe'],
            ['Lee, Na\'tavia', 'Na\'tavia', 'Lee'],
            ['Joshua Jon D\'agostino', 'Joshua', 'D\'agostino'],
            ['O\'connor, Kelly', 'Kelly', 'O\'connor'],
            ['Samantha O\'neil', 'Samantha', 'O\'neil'],
            ['Brian O\'connor', 'Brian', 'O\'connor'],
            ['Kelson, E\'shayla MHA', 'E\'shayla', 'Kelson'],
            ['N\'keyma Lee', 'N\'keyma', 'Lee'],
            ['Danielle O\'connell', 'Danielle', 'O\'connell'],
            ['April O\'neil', 'April', 'O\'neil'],
            ['D\'addario, Dawn', 'Dawn', 'D\'addario'],
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
            ['Vance J Van Tassell', 'Vance', 'Van Tassell'],
            ['Elizabeth De La Torre', 'Elizabeth', 'De La Torre'],
            ['Theresa Di Forti', 'Theresa', 'Di Forti'],
            ['Mila Le', 'Mila', 'Le'],
            ['Thuy Le', 'Thuy', 'Le'],
            ['Le, Elizabeth PHARMD', 'Elizabeth', 'Le'],
            ['Britt De Blonde', 'Britt', 'De Blonde'],
            ['Angelica De Rodriguez', 'Angelica', 'De Rodriguez'],
            ['Michelle De La Guardia', 'Michelle', 'De La Guardia'],
            ['Kevin Le', 'Kevin', 'Le'],
            ['Stacy Van Heeswyk', 'Stacy', 'Van Heeswyk'],
            ['Le, Isabella', 'Isabella', 'Le'],
            ['Srijisnu De', 'Srijisnu', 'De'],
            ['Mac, Ryan', 'Ryan', 'Mac'],
            ['Vivian Le', 'Vivian', 'Le'],
            ['Khuong Le', 'Khuong', 'Le'],
            ['Jaimee De Pompeo', 'Jaimee', 'De Pompeo'],
            ['Primrose Del Rosario', 'Primrose', 'Del Rosario'],
            ['Le, Catherine', 'Catherine', 'Le'],
            ['Jennifer Chen Wu', 'Jennifer', 'Chen Wu'],
            ['James Grant Allman Ii', 'James', 'Allman Ii'],
            ['Susan Von Rosk', 'Susan', 'Von Rosk'],
            ['Beatriz Del Villar', 'Beatriz', 'Del Villar'],
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

        $this->assertSame(
            mb_strtolower($first, 'UTF-8'),
            mb_strtolower($name->getFirstname(), 'UTF-8'),
            "first name for '$input'",
        );
        $this->assertSame(
            mb_strtolower($last, 'UTF-8'),
            mb_strtolower($name->getLastname(), 'UTF-8'),
            "last name for '$input'",
        );
    }
}
