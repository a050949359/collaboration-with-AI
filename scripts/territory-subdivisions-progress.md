# Territory 行政區匯入進度表

追蹤 `territory-import-subdivisions.py` 對每個國家的第一層行政區匯入狀態。
每次跑完一個國家（成功、無殘留 empty observation）就把該列的狀態改成 ✅ 並補上日期/筆數。

**進度：114 / 259 完成**

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
| Albania | Q222 | 2026-08-25 | 12 |
| Andorra | Q228 | 2026-08-25 | 7（7 教區 parish） |
| Belarus | Q184 | 2026-08-25 | 7（6 州 region + Minsk 首都單獨列 first_level_administrative_division） |
| Bosnia and Herzegovina | Q225 | 2026-08-25 | 3（Federation of BiH + Republika Srpska 兩實體 + Brčko District 自治區） |
| Kosovo | Q1246 | 2026-08-25 | 38（29 agy 自動 + 9 手動補：Prizren/Obiliq 因 description 不足被誤拒，Kllokot/Zvečan/North Mitrovica/Novo Brdo/Parteš/Skenderaj/Shtime 這 7 個 Wikidata 完全沒有 P150 連到 Q1246，由使用者提供 QID 逐一核對後手動補齊；Skenderaj(Q2043122) 與已收錄的 Drenas(Q860365) 座標/人口皆不同，確認是兩個不同市鎮非重複資料） |
| Luxembourg | Q32 | 2026-08-25 | 12 |
| Malta | Q233 | 2026-08-25 | 6 |
| Moldova | Q217 | 2026-08-25 | 37（36 自動 + Bălți Municipality 手動補，P31 誤標 second-level 但 description 跟 Chișinău/Bender 一致為 municipality） |
| Monaco | Q235 | 2026-08-25 | 1（單一 commune，1917 年已合併，符合微型國家常態） |
| Montenegro | Q236 | 2026-08-25 | 25 |
| North Macedonia | Q221 | 2026-08-25 | 81（實際為 80 個市鎮 opština + City of Skopje 這個額外協調層；agy 自動接受 71 個（含 City of Skopje 本身）+ 手動補 10 個被誤判為二級行政區的 Skopje 都會區下轄市鎮，這 10 個實際跟其他 70 個同屬 `opština`（Q646793）一級市鎮，City of Skopje 只是協調層非降級容器；另確認 Oslomej/Zajas/Drugovo/Vraneštica 4 個已於 2013 年併入 Kičevo，Wikidata 正確未連結，無需處理） |
| Norway | Q20 | 2026-08-25 | 18（16 自動 + Oslo 手動補 county 級 + Bouvet Island 手動補 first_level_administrative_division，比照已接受的 Jan Mayen/Svalbard） |
| Poland | Q36 | 2026-08-25 | 16 |
| Portugal | Q45 | 2026-08-25 | 20（18 district + Madeira/Azores autonomous_region） |
| Romania | Q218 | 2026-08-25 | 42（41 county + Bucharest first_level_administrative_division） |
| Russia | Q159 | 2026-08-25 | 83（85 候選扣 2 個爭議領土 Republic of Crimea/Sevastopol，國際普遍未承認為俄羅斯聯邦主體，正確排除未強制寫入） |
| San Marino | Q238 | 2026-08-25 | 9 |
| Serbia | Q403 | 2026-08-25 | 30（含 4 個涉及 Kosovo 領土的 district，是塞爾維亞官方立場上仍主張的行政區劃，與已建立的 Kosovo 國家實體地理重疊，屬政治爭議自然結果，未特別處理） |
| Slovakia | Q214 | 2026-08-25 | 8 |
| Slovenia | Q215 | 2026-08-25 | 212（200 municipality + 12 city_municipality，正好對上官方現行市鎮總數） |
| Sweden | Q34 | 2026-08-25 | 21 |
| Ukraine | Q212 | 2026-08-25 | 27（24 oblast + Autonomous Republic of Crimea 自動接受，符合國際普遍承認的烏克蘭領土範圍；Kyiv/Sevastopol 兩個特殊地位城市被 agy 誤拒，手動補上，同 Romania Bucharest/Russia Moscow 模式；Kyiv 首次 refresh_observations 出現已知的暫時性空值，重試後正常） |
| Vatican City | Q237 | 2026-08-25 | 0（Wikidata P150 無任何候選，符合梵蒂岡本身即單一行政單位、無次級行政區的實際狀況，微型國家常態，無需寫入） |
| Antigua and Barbuda | Q781 | 2026-08-25 | 8（6 教區 parish + Barbuda/Redonda 兩個附屬島嶼；Redonda 在正式寫入時被誤拒為「abandoned village」，dry-run 有通過，agy 判斷不穩定，手動補上） |
| The Bahamas | Q778 | 2026-08-25 | 32（27 自動 + 5 手動補：New Providence/Long Island/Mayaguana/Rum Cay/Moore's Island，Wikidata 只寫成 island 缺 district 標記，但候選總數剛好對上官方 32 個 district，同批 P150 查詢出來的資料完整度不一致） |
| Barbados | Q244 | 2026-08-25 | 11 |
| Belize | Q242 | 2026-08-25 | 6 |
| Costa Rica | Q800 | 2026-08-25 | 7 |
| Cuba | Q241 | 2026-08-25 | 16（15 省 + Isla de la Juventud 特殊市） |
| Dominica | Q784 | 2026-08-25 | 10 |
| Dominican Republic | Q786 | 2026-08-25 | 32（31 省 + Distrito Nacional 首都區） |
| El Salvador | Q792 | 2026-08-25 | 14 |
| Grenada | Q769 | 2026-08-25 | 7（6 教區 + Carriacou and Petite Martinique 合併 dependency 手動補：P150 只連到個別島嶼 QID（無行政意義），搜尋後找到正確的官方合併 dependency 實體 Q3044818，P31 含 first-level administrative division） |
| Guatemala | Q774 | 2026-08-25 | 22 |
| Haiti | Q790 | 2026-08-25 | 10 |
| Honduras | Q783 | 2026-08-25 | 18 |
| Jamaica | Q766 | 2026-08-25 | 14（全數手動建立：P150 只連到 3 個無治理功能的歷史郡 Middlesex/Surrey/Cornwall，現行實際治理單位是 14 個教區，郡未建成 entity，同 Ireland 傳統省處理原則） |
| Nicaragua | Q811 | 2026-08-25 | 17（15 department + 2 autonomous region） |
| Panama | Q804 | 2026-08-25 | 13（10 省 + 3 省級 comarca；2 個 corregimiento 等級的 comarca — Kuna de Wargandí/Madugandí — 正確排除，巴拿馬法律上行政位階等同二級行政區，非資料缺口） |
| Saint Kitts and Nevis | Q763 | 2026-08-25 | 14（9 個 Saint Kitts 教區 + 5 個 Nevis 教區） |
| Saint Lucia | Q760 | 2026-08-25 | 11（全部統一 type=quarter；Soufrière/Vieux Fort 因 Wikidata 標記不同一度被分到 type=district，刪除重建修正） |
| Saint Vincent and the Grenadines | Q757 | 2026-08-25 | 6 |
| Trinidad and Tobago | Q754 | 2026-08-25 | 15（14 自動 + Tobago 自治區手動補；agy 首次判斷把 type 過度泛化為 regional_corporation_or_municipality，依 Wikidata P31 精確分類手動修正 Port of Spain/San Fernando→city、Chaguanas→borough，Point Fortin 本來就對；Arima 因 Wikidata 本身無更細子分類，維持統稱） |
| Argentina | Q414 | 2026-08-25 | 24（23 省 + Buenos Aires 自治市手動補，P150 未連到 Q1486，P31 含 first-level administrative division，與 Buenos Aires Province 是不同實體） |
| Bolivia | Q750 | 2026-08-25 | 9 |
| Brazil | Q155 | 2026-08-25 | 27（25 自動 + Pernambuco/Tocantins 手動補，Wikidata 缺英文 label 顯示成裸 QID 被誤拒，同 Philippines Calabarzon 模式） |
| Chile | Q298 | 2026-08-25 | 16 |
| Colombia | Q739 | 2026-08-25 | 33（32 department + Bogotá 首都區） |
| Ecuador | Q736 | 2026-08-25 | 24（23 自動 + Loja Province 手動補：Wikidata 英文 label 被惡意塗改成粗俗字串，agy 正確識破拒絕，查證其他語言 label 確認真實身分後手動修正） |
| Guyana | Q734 | 2026-08-25 | 10（部分因 Essequibo 領土爭議標記 disputed territory，但為圭亞那實際治理區域，正確全數接受） |
| Paraguay | Q733 | 2026-08-25 | 18（17 department + Capital District Asunción） |
| Peru | Q419 | 2026-08-25 | 26（24 department + Callao 憲制省 + Lima Province 大都會利馬手動補，後者 P150 未連結但 P31 含 first-level administrative division，2002 年地方分權改革後不隸屬 Lima Region 政府，性質類似 Callao） |
| Suriname | Q730 | 2026-08-25 | 10 |
| Uruguay | Q77 | 2026-08-25 | 19 |
| Venezuela | Q717 | 2026-08-25 | 25（23 州 + Capital District + Federal Dependencies；候選數較多首次 agy 呼叫逾時，重試後正常） |
| Cook Islands | Q26988 | 2026-08-26 | 15（全數手動建立，type=island_council；Wikidata P150/P527 自動查詢完全找不到島嶼資料，經使用者逐一核對 QID 補齊南方群 8 島 + 北方群 7 島，含容易被誤認成小沙洲/Aitutaki 潟湖礁的雜訊排除） |
| Federated States of Micronesia | Q702 | 2026-08-26 | 4（Chuuk/Kosrae/Pohnpei/Yap；Pohnpei type 一度飄移成 federated_state，已統一為 state） |
| Fiji | Q712 | 2026-08-26 | 5（4 division + Rotuma 附屬島嶼） |
| Kiribati | Q710 | 2026-08-26 | 3（全數手動建立：Wikidata P150/P527 皆為 0，改用 Gilbert/Phoenix/Line Islands 三大島群作為第一層近似分類，取代 21 個實際無正式區域層級的島嶼時政；Line Islands 特別注意排除橫跨美國的泛稱實體 Q234796，改用 P17 只標 Kiribati 的 Q31866835） |
| Marshall Islands | Q709 | 2026-08-26 | 34（29 環礁 + 5 島，正好對上官方結構；28 自動 + 6 手動補：Bikini/Likiep/Utirik/Ujelang/Bikar 因描述或 P31 標記不完整被誤拒，Knox Atoll 則是 Wikidata P150 完全沒連到，由使用者提供 QID 找出） |
| Nauru | Q697 | 2026-08-26 | 14 |
| Niue | Q34020 | 2026-08-26 | 14（全數手動建立，type=village；Wikidata P150 為 0，用 P31=human settlement(Q486972)+P17=Niue 組合查詢找到全部 14 個，排除泛稱合併的 Alofi 實體避免與 Alofi North/South 重複計算） |
| Palau | Q695 | 2026-08-26 | 16（14 自動 + Melekeok/Koror 州手動補：兩個候選被拒的是城鎮/城市本體，非州本體，Q154002/Q527748 分別找到正確 state QID Q12898552/Q189426） |
| Papua New Guinea | Q691 | 2026-08-26 | 22（20 省 + 首都特區 + Bougainville 自治區） |
| Samoa | Q683 | 2026-08-26 | 10 |
| Solomon Islands | Q685 | 2026-08-26 | 10（9 省 + Honiara 首都市手動補，由獨立的 Honiara City Council 治理，不隸屬 Guadalcanal Province） |
| Tonga | Q678 | 2026-08-26 | 5 |
| Tuvalu | Q672 | 2026-08-26 | 9（全數手動建立，type=island_council；Wikidata P150 為 0，直接用 P31=human settlement 組合查詢太多雜訊，改用官方 9 島清單逐一搜尋確認） |
| Vanuatu | Q686 | 2026-08-26 | 6 |

## 待處理（144，另有 1 國暫緩見上方說明）

| 國家 | QID | code | 狀態 |
|---|---|---|---|
| Afghanistan | Q889 | AF | ⬜ |
| Algeria | Q262 | DZ | ⬜ |
| American Samoa | Q16641 | AS | ⬜ |
| Angola | Q916 | AO | ⬜ |
| Anguilla | Q25228 | AI | ⬜ |
| Antarctica | Q51 | AQ | ⬜ |
| Armenia | Q399 | AM | ⬜ |
| Aruba | Q21203 | AW | ⬜ |
| Ascension | Q31890709 | AC | ⬜ |
| Azerbaijan | Q227 | AZ | ⬜ |
| Bahrain | Q398 | BH | ⬜ |
| Bangladesh | Q902 | BD | ⬜ |
| Benin | Q962 | BJ | ⬜ |
| Bermuda | Q23635 | BM | ⬜ |
| Bhutan | Q917 | BT | ⬜ |
| Botswana | Q963 | BW | ⬜ |
| Bouvet Island | Q23408 | BV | ⬜ |
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
| Christmas Island | Q31063 | CX | ⬜ |
| Clipperton Island | Q161258 | CP | ⬜ |
| Cocos (Keeling) Islands | Q36004 | CC | ⬜ |
| Comoros | Q970 | KM | ⬜ |
| Curaçao | Q25279 | CW | ⬜ |
| Democratic Republic of the Congo | Q974 | CD | ⬜ |
| Diego Garcia | Q184851 | DG | ⬜ |
| Djibouti | Q977 | DJ | ⬜ |
| Equatorial Guinea | Q983 | GQ | ⬜ |
| Eritrea | Q986 | ER | ⬜ |
| Eswatini | Q1050 | SZ | ⬜ |
| Ethiopia | Q115 | ET | ⬜ |
| Falkland Islands | Q9648 | FK | ⬜ |
| Faroe Islands | Q4628 | FO | ⬜ |
| French Guiana | Q3769 | GF | ⬜ |
| French Polynesia | Q30971 | PF | ⬜ |
| French Southern and Antarctic Lands | Q129003 | TF | ⬜ |
| Gabon | Q1000 | GA | ⬜ |
| Georgia | Q230 | GE | ⬜ |
| German Democratic Republic | Q16957 | DD | ⬜ |
| Ghana | Q117 | GH | ⬜ |
| Gibraltar | Q1410 | GI | ⬜ |
| Greenland | Q223 | GL | ⬜ |
| Guadeloupe | Q17012 | GP | ⬜ |
| Guam | Q16635 | GU | ⬜ |
| Guernsey | Q25230 | GG | ⬜ |
| Guinea | Q1006 | GN | ⬜ |
| Guinea-Bissau | Q1007 | GW | ⬜ |
| Heard Island and McDonald Islands | Q131198 | HM | ⬜ |
| Iran | Q794 | IR | ⬜ |
| Iraq | Q796 | IQ | ⬜ |
| Isle of Man | Q9676 | IM | ⬜ |
| Israel | Q801 | IL | ⬜ |
| Ivory Coast | Q1008 | CI | ⬜ |
| Jersey | Q785 | JE | ⬜ |
| Jordan | Q810 | JO | ⬜ |
| Kazakhstan | Q232 | KZ | ⬜ |
| Kenya | Q114 | KE | ⬜ |
| Kuwait | Q817 | KW | ⬜ |
| Kyrgyzstan | Q813 | KG | ⬜ |
| Laos | Q819 | LA | ⬜ |
| Lebanon | Q822 | LB | ⬜ |
| Lesotho | Q1013 | LS | ⬜ |
| Liberia | Q1014 | LR | ⬜ |
| Libya | Q1016 | LY | ⬜ |
| Madagascar | Q1019 | MG | ⬜ |
| Malawi | Q1020 | MW | ⬜ |
| Maldives | Q826 | MV | ⚠️ 暫緩，見下方說明 |
| Mali | Q912 | ML | ⬜ |
| Martinique | Q17054 | MQ | ⬜ |
| Mauritania | Q1025 | MR | ⬜ |
| Mauritius | Q1027 | MU | ⬜ |
| Mayotte | Q17063 | YT | ⬜ |
| Mongolia | Q711 | MN | ⬜ |
| Montserrat | Q13353 | MS | ⬜ |
| Morocco | Q1028 | MA | ⬜ |
| Mozambique | Q1029 | MZ | ⬜ |
| Myanmar | Q836 | MM | ⬜ |
| Namibia | Q1030 | NA | ⬜ |
| Nepal | Q837 | NP | ⬜ |
| Netherlands Antilles | Q25227 | AN | ⬜ |
| New Caledonia | Q33788 | NC | ⬜ |
| Niger | Q1032 | NE | ⬜ |
| Nigeria | Q1033 | NG | ⬜ |
| Norfolk Island | Q31057 | NF | ⬜ |
| North Korea | Q423 | KP | ⬜ |
| Northern Mariana Islands | Q16644 | MP | ⬜ |
| Oman | Q842 | OM | ⬜ |
| Pakistan | Q843 | PK | ⬜ |
| Palestine | Q219060 | PS | ⬜ |
| Pitcairn Islands | Q35672 | PN | ⬜ |
| Puerto Rico | Q1183 | PR | ⬜ |
| Qatar | Q846 | QA | ⬜ |
| Republic of the Congo | Q971 | CG | ⬜ |
| Rwanda | Q1037 | RW | ⬜ |
| Réunion | Q17070 | RE | ⬜ |
| Saint Barthélemy | Q25362 | BL | ⬜ |
| Saint Helena, Ascension and Tristan da Cunha | Q192184 | SH | ⬜ |
| Saint Pierre and Miquelon | Q34617 | PM | ⬜ |
| Saint-Martin | Q126125 | MF | ⬜ |
| Sark | Q3405693 | CQ | ⬜ |
| Saudi Arabia | Q851 | SA | ⬜ |
| Senegal | Q1041 | SN | ⬜ |
| Seychelles | Q1042 | SC | ⬜ |
| Sierra Leone | Q1044 | SL | ⬜ |
| Sint Maarten | Q26273 | SX | ⬜ |
| Somalia | Q1045 | SO | ⬜ |
| South Africa | Q258 | ZA | ⬜ |
| South Georgia and the South Sandwich Islands | Q35086 | GS | ⬜ |
| South Sudan | Q958 | SS | ⬜ |
| Sri Lanka | Q854 | LK | ⬜ |
| Sudan | Q1049 | SD | ⬜ |
| Svalbard and Jan Mayen | Q842829 | SJ | ⬜ |
| Syria | Q858 | SY | ⬜ |
| São Tomé and Príncipe | Q1039 | ST | ⬜ |
| Tajikistan | Q863 | TJ | ⬜ |
| Tanzania | Q924 | TZ | ⬜ |
| The Gambia | Q1005 | GM | ⬜ |
| Timor-Leste | Q574 | TL | ⬜ |
| Togo | Q945 | TG | ⬜ |
| Tokelau | Q36823 | TK | ⬜ |
| Tristan da Cunha | Q34625512 | TA | ⬜ |
| Trust Territory of the Pacific Islands | Q129237 | PC | ⬜ |
| Tunisia | Q948 | TN | ⬜ |
| Turkmenistan | Q874 | TM | ⬜ |
| Turks and Caicos Islands | Q18221 | TC | ⬜ |
| Uganda | Q1036 | UG | ⬜ |
| United States Minor Outlying Islands | Q16645 | UM | ⬜ |
| United States Virgin Islands | Q11703 | VI | ⬜ |
| Uzbekistan | Q265 | UZ | ⬜ |
| Wallis and Futuna | Q35555 | WF | ⬜ |
| Western Sahara | Q6250 | EH | ⬜ |
| Yemen | Q805 | YE | ⬜ |
| Yugoslavia | Q36704 | YU | ⬜ |
| Zambia | Q953 | ZM | ⬜ |
| Zimbabwe | Q954 | ZW | ⬜ |
| Åland | Q5689 | AX | ⬜ |
