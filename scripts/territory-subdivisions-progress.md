# Territory 行政區匯入進度表

追蹤 `territory-import-subdivisions.py` 對每個國家的第一層行政區匯入狀態。
每次跑完一個國家（成功、無殘留 empty observation）就把該列的狀態改成 ✅ 並補上日期/筆數。

**進度：45 / 259 完成**

## ⚠️ 暫緩處理的國家

**Maldives（Q826）**：Wikidata 的 P150（國家→第一層行政區）只回傳 20 個「行政環礁」，但實際現行官方行政架構是 **18 個行政環礁 + 5 個市**（Malé City、Addu City、Fuvahmulah City、Kulhudhuffushi City、Thinadhoo City）。核對發現 Wikidata 對這 5 個市的資料很不完整：只有 Malé（`Q9347`，P31 含明確的 `first-level administrative division`）跟 Addu City（`Q4681407`，獨立城市 entity 但沒有 P150 連到 Maldives）有乾淨的城市 QID；Fuvahmulah 只能對應回舊的環礁 entity（`Q1811116` Gnaviyani Atoll）；Kulhudhuffushi、Thinadhoo 完全沒有城市層級的 entity，只有島嶼 entity。原本 20 個環礁要扣哪 2 個變成 18 個，也需要跟官方資料源核對才能確定，不能用猜的。**尚未寫入任何資料**（只跑過 dry-run）。需要人工找到官方/可靠來源核對完整名單後才能繼續，不要在自動化流程中被跳過或誤選為「下一國」。

## 已完成

| 國家 | QID | 完成日期 | 一級行政區數 |
|---|---|---|---|
| Taiwan | Q865 | 2026-08-17 | 6 |
| Japan | Q17 | 2026-08-21 | 47 |
| South Korea | Q884 | 2026-08-21 | 17 |
| Thailand | Q869 | 2026-08-21 | 77 |
| Singapore | Q334 | 2026-08-21 | 5 |
| Vietnam | Q881 | 2026-08-21 | 34（Q26575 Thái Nguyên 暫無 observation,待最後統一補） |
| Malaysia | Q833 | 2026-08-21 | 16 |
| Philippines | Q928 | 2026-08-21 | 18（Q13650/Calabarzon 因 Wikidata 缺英文 label 被 agy 誤拒，手動補上） |
| Indonesia | Q252 | 2026-08-21 | 38（4 個已廢除歷史省份正確排除） |
| China | Q148 | 2026-08-21 | 33（含 Hong Kong/Macau，兩者沿用 country layer 既有的 type=country，只新增 part_of 關係） |
| Hong Kong | Q8646 | 2026-08-21 | — （China 的 dependency，本身即節點，非獨立跑 subdivision import；已隨 China 那批建 part_of） |
| Macau | Q14773 | 2026-08-21 | — （同上，China 的 dependency） |
| India | Q668 | 2026-08-21 | 36（3 個已廢除/非第一層行政區正確排除，含 2019 改制前的舊 Jammu and Kashmir 邦） |
| Australia | Q408 | 2026-08-21 | 16（6 州 + 7 external territory + 3 mainland territory） |
| United States | Q30 | 2026-08-21 | 56（50 州 + 5 territory + DC federal_district） |
| United Kingdom | Q145 | 2026-08-21 | 4（Scotland/England/Northern Ireland/Wales） |
| France | Q142 | 2026-08-21 | 25（Clipperton Island 這次被 agy 合理拒絕，無人島環礁行政性質存疑，未強制補） |
| Germany | Q183 | 2026-08-21 | 16（3 個 1952 年前已廢除的舊西德邦正確排除） |
| Italy | Q38 | 2026-08-21 | 20（15 region + 5 autonomous_region） |
| Spain | Q29 | 2026-08-21 | 19（plazas de soberanía 正確拒絕，領土泛稱非單一行政區） |
| Netherlands（Kingdom, Q29999） | Q29999 | 2026-08-21 | 4，全數手動處理（本地 countries 表 NL 對應 Q29999 非 Q55，需用王國 QID 查；Aruba/Curaçao/Sint Maarten 沿用既有 type=country 只補 part_of；Q55 荷蘭本土全新建 type=country，P297 為 deprecated rank 故無 recognized/status/notes，符合預期） |
| Canada | Q16 | 2026-08-21 | 13（10 省 + 3 地區） |
| Switzerland | Q39 | 2026-08-21 | 26（22 canton + 4 half_canton） |
| Turkey | Q43 | 2026-08-21 | 81 |
| Greece | Q41 | 2026-08-21 | 14（13 administrative_region + Mount Athos autonomous_region） |
| New Zealand | Q664 | 2026-08-24 | 17（16 自動 + Chatham Islands 手動補；正確 QID 為 Q86771569「Chatham Islands Territory」，非最初誤用的 Q26882619「Chatham Islands Council」——後者是治理機關本體非行政區域，已刪除重建） |
| Mexico | Q96 | 2026-08-24 | 32 |
| Egypt | Q79 | 2026-08-24 | 27（1 個已廢除歷史 governorate 正確排除） |
| United Arab Emirates | Q878 | 2026-08-24 | 7 |
| Cambodia | Q424 | 2026-08-24 | 24 |
| Austria | Q40 | 2026-08-24 | 9 |
| Belgium | Q31 | 2026-08-24 | 6 |
| Bulgaria | Q219 | 2026-08-24 | 28 |
| Croatia | Q224 | 2026-08-24 | 21（20 自動 + Zagreb 手動補，county 級直轄市） |
| Cyprus | Q229 | 2026-08-24 | 6 |
| Czech Republic | Q213 | 2026-08-24 | 14 |
| Denmark | Q35 | 2026-08-24 | 5（3 自動 + 2 手動補，agy 誤判「(2007–2026)」有效期標示為不確定；Kingdom of Denmark(Q756617)含 Faroe/Greenland 的王國層暫不處理，見記憶 follow-up 12） |
| Estonia | Q191 | 2026-08-24 | 15 |
| Finland | Q33 | 2026-08-24 | 19 |
| Hungary | Q28 | 2026-08-24 | 19（20 候選扣 1 個真正的二級行政區 Csongrád-Csanád County；agy 該次誤 accept 它、又誤 reject 2 個現行郡，皆已手動修正） |
| Iceland | Q189 | 2026-08-24 | 8，⚠️ 非官方治理行政區，全數手動寫入（Wikidata P150 對 Iceland 只連到 6 個國會選舉區 kjördæmi，不是行政區；冰島實際上中央政府直轄 ~64 個市鎮，沒有正式的中間行政層級；這 8 個是 ISO 3166-2:IS 統計/分類用區域 IS-1~IS-8，非國家規劃的治理行政區，僅作為本專案「第一層」的替代近似值） |
| Ireland | Q27 | 2026-08-24 | 31（完整對上現行 31 個地方政府單位。4 傳統省 Leinster/Munster/Connacht/Ulster 正確排除，無治理功能，改以 `traditional_province` observation 記在對應郡上；Dublin/Tipperary 被 agy 正確拒絕為已廢除舊制，手動補上 Dublin 4 郡(Fingal/South Dublin/Dún Laoghaire–Rathdown/Dublin City)+ Tipperary(Q184618，P31 preferred rank 標 former 但地理範圍即現行統一後郡，決定沿用)+ Cork City(Q36647)+ Galway City(Q133862337)——後 2 個一開始誤判「無獨立實體」，其實跟 Dublin City 同屬 `Q13455645` administrative-unit 分類，複查後才找到） |
| Latvia | Q211 | 2026-08-24 | 41 |
| Liechtenstein | Q347 | 2026-08-24 | 11（第一次 dry-run 遇到 agy 服務暫時 503，重試後正常） |
| Lithuania | Q37 | 2026-08-24 | 60（59 自動 + Neringa Municipality Q9305847 手動補，Wikidata 對它完全無 P150）；⚠️ 第一層改用市鎮(savivaldybė)而非傳統的 10 個郡(apskritis)——郡已於 2010 年廢除治理機關(縣長辦公室)，現在只是統計/地理分區，無議會無治理權，性質等同 Ireland 的傳統省；郡資訊之後可比照 Ireland 用 `traditional_province`-style observation 補在各市鎮上，尚未執行 |

## 待處理（228，另有 1 國暫緩見上方說明）

| 國家 | QID | code | 狀態 |
|---|---|---|---|
| Afghanistan | Q889 | AF | ⬜ |
| Albania | Q222 | AL | ⬜ |
| Algeria | Q262 | DZ | ⬜ |
| American Samoa | Q16641 | AS | ⬜ |
| Andorra | Q228 | AD | ⬜ |
| Angola | Q916 | AO | ⬜ |
| Anguilla | Q25228 | AI | ⬜ |
| Antarctica | Q51 | AQ | ⬜ |
| Antigua and Barbuda | Q781 | AG | ⬜ |
| Argentina | Q414 | AR | ⬜ |
| Armenia | Q399 | AM | ⬜ |
| Aruba | Q21203 | AW | ⬜ |
| Ascension | Q31890709 | AC | ⬜ |
| Azerbaijan | Q227 | AZ | ⬜ |
| Bahrain | Q398 | BH | ⬜ |
| Bangladesh | Q902 | BD | ⬜ |
| Barbados | Q244 | BB | ⬜ |
| Belarus | Q184 | BY | ⬜ |
| Belize | Q242 | BZ | ⬜ |
| Benin | Q962 | BJ | ⬜ |
| Bermuda | Q23635 | BM | ⬜ |
| Bhutan | Q917 | BT | ⬜ |
| Bolivia | Q750 | BO | ⬜ |
| Bosnia and Herzegovina | Q225 | BA | ⬜ |
| Botswana | Q963 | BW | ⬜ |
| Bouvet Island | Q23408 | BV | ⬜ |
| Brazil | Q155 | BR | ⬜ |
| British Indian Ocean Territory | Q43448 | IO | ⬜ |
| British Virgin Islands | Q25305 | VG | ⬜ |
| Brunei | Q921 | BN | ⬜ |
| Burkina Faso | Q965 | BF | ⬜ |
| Burundi | Q967 | BI | ⬜ |
| Cameroon | Q1009 | CM | ⬜ |
| Cape Verde | Q1011 | CV | ⬜ |
| Caribbean Netherlands | Q27561 | BQ | ⬜ |
| Cayman Islands | Q5785 | KY | ⬜ |
| Central African Republic | Q929 | CF | ⬜ |
| Chad | Q657 | TD | ⬜ |
| Chile | Q298 | CL | ⬜ |
| Christmas Island | Q31063 | CX | ⬜ |
| Clipperton Island | Q161258 | CP | ⬜ |
| Cocos (Keeling) Islands | Q36004 | CC | ⬜ |
| Colombia | Q739 | CO | ⬜ |
| Comoros | Q970 | KM | ⬜ |
| Cook Islands | Q26988 | CK | ⬜ |
| Costa Rica | Q800 | CR | ⬜ |
| Cuba | Q241 | CU | ⬜ |
| Curaçao | Q25279 | CW | ⬜ |
| Democratic Republic of the Congo | Q974 | CD | ⬜ |
| Diego Garcia | Q184851 | DG | ⬜ |
| Djibouti | Q977 | DJ | ⬜ |
| Dominica | Q784 | DM | ⬜ |
| Dominican Republic | Q786 | DO | ⬜ |
| Ecuador | Q736 | EC | ⬜ |
| El Salvador | Q792 | SV | ⬜ |
| Equatorial Guinea | Q983 | GQ | ⬜ |
| Eritrea | Q986 | ER | ⬜ |
| Eswatini | Q1050 | SZ | ⬜ |
| Ethiopia | Q115 | ET | ⬜ |
| Falkland Islands | Q9648 | FK | ⬜ |
| Faroe Islands | Q4628 | FO | ⬜ |
| Federated States of Micronesia | Q702 | FM | ⬜ |
| Fiji | Q712 | FJ | ⬜ |
| French Guiana | Q3769 | GF | ⬜ |
| French Polynesia | Q30971 | PF | ⬜ |
| French Southern and Antarctic Lands | Q129003 | TF | ⬜ |
| Gabon | Q1000 | GA | ⬜ |
| Georgia | Q230 | GE | ⬜ |
| German Democratic Republic | Q16957 | DD | ⬜ |
| Ghana | Q117 | GH | ⬜ |
| Gibraltar | Q1410 | GI | ⬜ |
| Greenland | Q223 | GL | ⬜ |
| Grenada | Q769 | GD | ⬜ |
| Guadeloupe | Q17012 | GP | ⬜ |
| Guam | Q16635 | GU | ⬜ |
| Guatemala | Q774 | GT | ⬜ |
| Guernsey | Q25230 | GG | ⬜ |
| Guinea | Q1006 | GN | ⬜ |
| Guinea-Bissau | Q1007 | GW | ⬜ |
| Guyana | Q734 | GY | ⬜ |
| Haiti | Q790 | HT | ⬜ |
| Heard Island and McDonald Islands | Q131198 | HM | ⬜ |
| Honduras | Q783 | HN | ⬜ |
| Iran | Q794 | IR | ⬜ |
| Iraq | Q796 | IQ | ⬜ |
| Isle of Man | Q9676 | IM | ⬜ |
| Israel | Q801 | IL | ⬜ |
| Ivory Coast | Q1008 | CI | ⬜ |
| Jamaica | Q766 | JM | ⬜ |
| Jersey | Q785 | JE | ⬜ |
| Jordan | Q810 | JO | ⬜ |
| Kazakhstan | Q232 | KZ | ⬜ |
| Kenya | Q114 | KE | ⬜ |
| Kiribati | Q710 | KI | ⬜ |
| Kosovo | Q1246 | XK | ⬜ |
| Kuwait | Q817 | KW | ⬜ |
| Kyrgyzstan | Q813 | KG | ⬜ |
| Laos | Q819 | LA | ⬜ |
| Lebanon | Q822 | LB | ⬜ |
| Lesotho | Q1013 | LS | ⬜ |
| Liberia | Q1014 | LR | ⬜ |
| Libya | Q1016 | LY | ⬜ |
| Luxembourg | Q32 | LU | ⬜ |
| Madagascar | Q1019 | MG | ⬜ |
| Malawi | Q1020 | MW | ⬜ |
| Maldives | Q826 | MV | ⚠️ 暫緩，見下方說明 |
| Mali | Q912 | ML | ⬜ |
| Malta | Q233 | MT | ⬜ |
| Marshall Islands | Q709 | MH | ⬜ |
| Martinique | Q17054 | MQ | ⬜ |
| Mauritania | Q1025 | MR | ⬜ |
| Mauritius | Q1027 | MU | ⬜ |
| Mayotte | Q17063 | YT | ⬜ |
| Moldova | Q217 | MD | ⬜ |
| Monaco | Q235 | MC | ⬜ |
| Mongolia | Q711 | MN | ⬜ |
| Montenegro | Q236 | ME | ⬜ |
| Montserrat | Q13353 | MS | ⬜ |
| Morocco | Q1028 | MA | ⬜ |
| Mozambique | Q1029 | MZ | ⬜ |
| Myanmar | Q836 | MM | ⬜ |
| Namibia | Q1030 | NA | ⬜ |
| Nauru | Q697 | NR | ⬜ |
| Nepal | Q837 | NP | ⬜ |
| Netherlands Antilles | Q25227 | AN | ⬜ |
| New Caledonia | Q33788 | NC | ⬜ |
| Nicaragua | Q811 | NI | ⬜ |
| Niger | Q1032 | NE | ⬜ |
| Nigeria | Q1033 | NG | ⬜ |
| Niue | Q34020 | NU | ⬜ |
| Norfolk Island | Q31057 | NF | ⬜ |
| North Korea | Q423 | KP | ⬜ |
| North Macedonia | Q221 | MK | ⬜ |
| Northern Mariana Islands | Q16644 | MP | ⬜ |
| Norway | Q20 | NO | ⬜ |
| Oman | Q842 | OM | ⬜ |
| Pakistan | Q843 | PK | ⬜ |
| Palau | Q695 | PW | ⬜ |
| Palestine | Q219060 | PS | ⬜ |
| Panama | Q804 | PA | ⬜ |
| Papua New Guinea | Q691 | PG | ⬜ |
| Paraguay | Q733 | PY | ⬜ |
| Peru | Q419 | PE | ⬜ |
| Pitcairn Islands | Q35672 | PN | ⬜ |
| Poland | Q36 | PL | ⬜ |
| Portugal | Q45 | PT | ⬜ |
| Puerto Rico | Q1183 | PR | ⬜ |
| Qatar | Q846 | QA | ⬜ |
| Republic of the Congo | Q971 | CG | ⬜ |
| Romania | Q218 | RO | ⬜ |
| Russia | Q159 | RU | ⬜ |
| Rwanda | Q1037 | RW | ⬜ |
| Réunion | Q17070 | RE | ⬜ |
| Saint Barthélemy | Q25362 | BL | ⬜ |
| Saint Helena, Ascension and Tristan da Cunha | Q192184 | SH | ⬜ |
| Saint Kitts and Nevis | Q763 | KN | ⬜ |
| Saint Lucia | Q760 | LC | ⬜ |
| Saint Pierre and Miquelon | Q34617 | PM | ⬜ |
| Saint Vincent and the Grenadines | Q757 | VC | ⬜ |
| Saint-Martin | Q126125 | MF | ⬜ |
| Samoa | Q683 | WS | ⬜ |
| San Marino | Q238 | SM | ⬜ |
| Sark | Q3405693 | CQ | ⬜ |
| Saudi Arabia | Q851 | SA | ⬜ |
| Senegal | Q1041 | SN | ⬜ |
| Serbia | Q403 | RS | ⬜ |
| Seychelles | Q1042 | SC | ⬜ |
| Sierra Leone | Q1044 | SL | ⬜ |
| Sint Maarten | Q26273 | SX | ⬜ |
| Slovakia | Q214 | SK | ⬜ |
| Slovenia | Q215 | SI | ⬜ |
| Solomon Islands | Q685 | SB | ⬜ |
| Somalia | Q1045 | SO | ⬜ |
| South Africa | Q258 | ZA | ⬜ |
| South Georgia and the South Sandwich Islands | Q35086 | GS | ⬜ |
| South Sudan | Q958 | SS | ⬜ |
| Sri Lanka | Q854 | LK | ⬜ |
| Sudan | Q1049 | SD | ⬜ |
| Suriname | Q730 | SR | ⬜ |
| Svalbard and Jan Mayen | Q842829 | SJ | ⬜ |
| Sweden | Q34 | SE | ⬜ |
| Syria | Q858 | SY | ⬜ |
| São Tomé and Príncipe | Q1039 | ST | ⬜ |
| Tajikistan | Q863 | TJ | ⬜ |
| Tanzania | Q924 | TZ | ⬜ |
| The Bahamas | Q778 | BS | ⬜ |
| The Gambia | Q1005 | GM | ⬜ |
| Timor-Leste | Q574 | TL | ⬜ |
| Togo | Q945 | TG | ⬜ |
| Tokelau | Q36823 | TK | ⬜ |
| Tonga | Q678 | TO | ⬜ |
| Trinidad and Tobago | Q754 | TT | ⬜ |
| Tristan da Cunha | Q34625512 | TA | ⬜ |
| Trust Territory of the Pacific Islands | Q129237 | PC | ⬜ |
| Tunisia | Q948 | TN | ⬜ |
| Turkmenistan | Q874 | TM | ⬜ |
| Turks and Caicos Islands | Q18221 | TC | ⬜ |
| Tuvalu | Q672 | TV | ⬜ |
| Uganda | Q1036 | UG | ⬜ |
| Ukraine | Q212 | UA | ⬜ |
| United States Minor Outlying Islands | Q16645 | UM | ⬜ |
| United States Virgin Islands | Q11703 | VI | ⬜ |
| Uruguay | Q77 | UY | ⬜ |
| Uzbekistan | Q265 | UZ | ⬜ |
| Vanuatu | Q686 | VU | ⬜ |
| Vatican City | Q237 | VA | ⬜ |
| Venezuela | Q717 | VE | ⬜ |
| Wallis and Futuna | Q35555 | WF | ⬜ |
| Western Sahara | Q6250 | EH | ⬜ |
| Yemen | Q805 | YE | ⬜ |
| Yugoslavia | Q36704 | YU | ⬜ |
| Zambia | Q953 | ZM | ⬜ |
| Zimbabwe | Q954 | ZW | ⬜ |
| Åland | Q5689 | AX | ⬜ |
