# Territory 行政區匯入進度表

追蹤 `territory-import-subdivisions.py` 對每個國家的第一層行政區匯入狀態。
每次跑完一個國家（成功、無殘留 empty observation）就把該列的狀態改成 ✅ 並補上日期/筆數。

**進度：184 / 259 完成**

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
| Bahrain | Q398 | 2026-08-26 | 4（Capital/Muharraq/Northern/Southern；Central Governorate 已於 2014 年正式廢除（P576 有廢除日期），agy 誤收後刪除排除；Muharraq 的 Wikidata description 被惡意塗改成「governorate of botak」導致誤拒，已手動修正描述後補入） |
| Iran | Q794 | 2026-08-26 | 31 |
| Iraq | Q796 | 2026-08-26 | 18（Kurdistan 自治區為跨省上層概念，P150 未連結，不在此次一級行政區清單） |
| Israel | Q801 | 2026-08-26 | 6（「Judea and Samaria Area」是以色列對約旦河西岸的行政劃分名稱，國際普遍認定為被佔領巴勒斯坦領土，同 Russia/Crimea 政治爭議類型，正確排除未強制寫入） |
| Jordan | Q810 | 2026-08-26 | 12 |
| Kuwait | Q817 | 2026-08-26 | 6 |
| Lebanon | Q822 | 2026-08-26 | 8（6 自動 + Akkar/Baalbek-Hermel 手動補，Wikidata P150 沒連到這兩個 2003/2017 年才新設的省份） |
| Oman | Q842 | 2026-08-26 | 11 |
| Palestine | Q219060 | 2026-08-26 | 16（11 西岸 + 5 加薩省；West Bank/Gaza Strip 兩個地理區域泛稱正確排除，非個別行政區） |
| Qatar | Q846 | 2026-08-26 | 8（5 自動 + Al Wakrah/Al Shahaniya/Al Daayen 手動補：前兩個候選被拒的是城市/聚落本體非市鎮本體，各自找到正確市鎮 QID） |
| Saudi Arabia | Q851 | 2026-08-26 | 13（全數手動建立；P150 完全查不到真正省份只連到維基百科列表條目，改用 P31 分類代碼 Q15728204 直接查詢找到全部 13 省，排除疑似二級行政區的 Al-Qurayyat） |
| Syria | Q858 | 2026-08-26 | 14 |
| Yemen | Q805 | 2026-08-26 | 22 |
| Kazakhstan | Q232 | 2026-08-26 | 17（14 州 + 3 共和國直轄市；Baikonur 太空發射場屬俄羅斯長期租借代管的特殊安排，正確排除） |
| Kyrgyzstan | Q813 | 2026-08-26 | 9（全數手動建立；P150 幾乎查不到，改用 P31 分類代碼補齊 7 州 + 2 共和國直轄市 Bishkek/Osh） |
| Tajikistan | Q863 | 2026-08-26 | 5（3 州 + 中央直轄區 + 首都 Dushanbe） |
| Turkmenistan | Q874 | 2026-08-26 | 6（5 州 + 首都 Ashgabat） |
| Uzbekistan | Q265 | 2026-08-26 | 14（12 州 + Karakalpakstan 自治共和國 + 首都 Tashkent） |
| North Korea | Q423 | 2026-08-26 | 12（9 道 + 平壤直轄市 + 羅先、南浦 2 特級市；南浦被 agy 誤拒，手動補入 special_level_city） |
| Mongolia | Q711 | 2026-08-27 | 22（21 省 + 首都 Ulaanbaatar） |
| Afghanistan | Q889 | 2026-08-31 | 34 |
| Armenia | Q399 | 2026-08-31 | 11（10 省 + 首都 Yerevan） |
| Azerbaijan | Q227 | 2026-08-31 | 69（60 個區 raion 自動接受 + 9 個手動補：Aghdara District 因 2023-12 才重新建制、Wikidata 尚無 P150 連結，另 8 個共和國直轄市 Khankendi/Ganja/Sumgait/Naftalan/Yevlakh/Lankaran/Mingachevir/Shaki 皆被 agy 誤拒為「僅普通城市」，經查 P31 class Q56557664「şəhər：city and type of administrative subdivision of Azerbaijan」確認與 Baku 同層級，手動補入；Shusha 經核對其 P31 僅為普通 city 且 description 明示為 Shusha District 行政中心，正確排除未列入） |
| Bangladesh | Q902 | 2026-08-31 | 8 |
| Bhutan | Q917 | 2026-08-31 | 20 |
| Brunei | Q921 | 2026-08-31 | 4 |
| Georgia | Q230 | 2026-08-31 | 12（9 個 Mkhare + Tbilisi 自治市 + Adjara/Abkhazia 兩自治共和國；Abkhazia 雖為俄國佔領下實際分離狀態，但國際普遍承認為喬治亞領土，非爭議排除案例） |
| Laos | Q819 | 2026-08-31 | 18（16 省自動 + Xaisomboun 省因較晚設立、Wikidata 缺 P150 手動補 + 萬象直轄市因描述僅寫 conurbation 被誤拒、經查 P31 確實含 province of Laos 手動補入） |
| Myanmar | Q836 | 2026-08-31 | 15（7 Region + 7 State + Naypyidaw 聯邦特區；6 個自治區/自治縣正確排除，屬巢狀於 Shan State/Sagaing Region 底下的次層級） |
| Nepal | Q837 | 2026-08-31 | 7 |
| Pakistan | Q843 | 2026-08-31 | 7（4 省 + Islamabad 首都特區 + Gilgit-Baltistan + Azad Kashmir，皆為巴基斯坦實際治理的一級行政區；Junagadh and Manavadar 僅為歷史主張、從未實際治理過，正確排除） |
| Sri Lanka | Q854 | 2026-08-31 | 9 |
| Timor-Leste | Q574 | 2026-08-31 | 14（13 municipality，含 2022 年升格的 Atauro + Oe-Cusse Ambeno 特別行政區） |
| Algeria | Q262 | 2026-08-31 | 58 |
| Angola | Q916 | 2026-08-31 | 21（17 自動 + Cuando Cubango 已於 2024-09 拆分為 Cuando/Cubango 正確排除 + 手動補 Cuando/Cubango 兩新省 + Luanda 分出的 Icolo e Bengo + Moxico 分出的 Moxico Leste，2024 年行政重劃 18→21 省，Wikidata 對新省 P150 尚未連結） |
| Benin | Q962 | 2026-08-31 | 12 |
| Botswana | Q963 | 2026-08-31 | 17（9 個 District Council 自動 + 8 個手動補：漏抓的 Chobe District（P31 與其餘 9 區同屬 first-level administrative subdivision class）+ 2 City Council（Gaborone/Francistown）+ 5 Town Council（Lobatse/Selebi-Phikwe/Orapa/Jwaneng/Sowa）——官方地方政府法下城鎮議會與郡議會為並列一級行政單位，但 Wikidata 對這些城鎮僅標一般 city/town class，無獨立行政區 class，仍依實際治理結構補入） |
| Burkina Faso | Q965 | 2026-08-31 | 13（11 自動 + 2 手動補：Djôrô/Guiriko 為 2024 年地名去殖民化改制後的新名稱，分別對應原 Sud-Ouest/Hauts-Bassins Region，P31 與其餘 11 區相同 class，agy 誤讀 description 文字裡的舊名稱當作「位於其內」而誤拒） |
| Burundi | Q967 | 2026-08-31 | 17 |
| Cameroon | Q1009 | 2026-08-31 | 10 |
| Cape Verde | Q1011 | 2026-08-31 | 22 |
| Central African Republic | Q929 | 2026-08-31 | 17（15 自動 + Mbomou/Ouham 兩省手動補，Wikidata 對它們完全無 P150） |
| Chad | Q657 | 2026-08-31 | 23（21 省自動 + N'Djamena 特殊行政區自動 + 刪除已於 2012 廢除的舊版 Ennedi Region（agy 漏檢 P576 廢除日期誤收）、改手動補入現行的 Ennedi-Est/Ennedi-Ouest 兩省） |
| Comoros | Q970 | 2026-08-31 | 3 |
| Democratic Republic of the Congo | Q974 | 2026-08-31 | 26（25 省 + Kinshasa 首都） |
| Djibouti | Q977 | 2026-08-31 | 6 |
| Equatorial Guinea | Q983 | 2026-08-31 | 8（含 2017 年新設的 Djibloho 省） |
| Eritrea | Q986 | 2026-08-31 | 6 |
| Eswatini | Q1050 | 2026-08-31 | 4 |
| Ethiopia | Q115 | 2026-08-31 | 14（12 州 + Addis Ababa/Dire Dawa 兩特許市，對上 2023 年 SNNPR 拆分為 Sidama/Southwest/South/Central 4 州後的現行結構） |
| Gabon | Q1000 | 2026-08-31 | 9 |
| Ghana | Q117 | 2026-08-31 | 16（2018 年新增 6 區後的現行數目） |
| Guinea | Q1006 | 2026-09-01 | 8（7 自動 + Conakry 特別區手動補，被誤拒為僅普通城市） |
| Guinea-Bissau | Q1007 | 2026-09-01 | 9（8 個 region + Bissau 自治區） |
| Ivory Coast | Q1008 | 2026-09-01 | 14，全數手動建立（Wikidata P150 抓到的 19 個候選全部是 2011 年行政改制已廢除的舊制 19 大區，agy 只抓到 1 個 P576 廢除標記漏了另外 18 個；現行結構是 12 般 district + Abidjan/Yamoussoukro 2 自治區＝14 District，31 個 Region 為第二層非第一層，改查 class Q20717263「district of Ivory Coast」找到完整 14 個現行清單） |
| Kenya | Q114 | 2026-09-01 | 47，全數手動建立（P150 候選的舊 8 省制已於 2013 年廢除，agy 正確全數排除；改查現行郡 class Q269218 找到完整 47 個 county，剛好對上肯亞 2013 年新憲法後的現行結構） |
| Lesotho | Q1013 | 2026-09-01 | 10 |
| Liberia | Q1014 | 2026-09-01 | 15 |
| Libya | Q1016 | 2026-09-01 | 21（6 個同名城市實體正確排除，屬所在 district 的下級城鎮/首都城市，非第一層） |
| Madagascar | Q1019 | 2026-09-01 | 24，全數手動建立（P150 候選的舊 6 省制已於 2004 年廢除，agy 又漏檢 P576；改查 class Q971831 找到現行 25 個候選，扣除已於後續改制廢除的 Vatovavy-Fitovinany（已拆分為 Vatovavy/Fitovinany 兩區）= 24，含 2025 年 7 月才正式就職的最新第 24 區 Ambatosoa） |
| Malawi | Q1020 | 2026-09-01 | 3 |
| Mali | Q912 | 2026-09-01 | 20（19 個 Region + Bamako 首都區，對上 2023 年行政重劃後現行結構） |
| Mauritania | Q1025 | 2026-09-02 | 15（12 自動 + 3 手動補：首都 Nouakchott 已於 2018 年拆分為 Nouakchott-Nord/Ouest/Sud 三個獨立 region，舊版單一城市 entity 正確排除） |
| Mauritius | Q1027 | 2026-09-02 | 10（9 district 自動 + Rodrigues 自治島手動補，P31 明確標示 first-level administrative division 但無 P150 連結） |
| Morocco | Q1028 | 2026-09-02 | 10（本土 10 個 region；Laâyoune-Sakia El Hamra/Dakhla-Oued Ed-Dahab 涵蓋西撒哈拉爭議領土，摩洛哥雖實際控制但國際普遍未承認主權，比照 Russia/Crimea、Israel/West Bank 案例排除——dry-run 時 agy 正確拒絕，但正式寫入時判斷不一致誤收，已手動刪除2個實體修正） |
| Mozambique | Q1029 | 2026-09-03 | 11（10 省自動 + 首都 Maputo City 手動補，與省同級的獨立直轄市） |
| Namibia | Q1030 | 2026-09-03 | 14 |
| Niger | Q1032 | 2026-09-03 | 8（7 個 region + Niamey 首都區） |
| Nigeria | Q1033 | 2026-09-03 | 37（36 州 + Federal Capital Territory） |
| Republic of the Congo | Q971 | 2026-09-03 | 15（13 個 department 自動，含 2023 年新設的 Nkéni-Alima/Congo-Oubangui/Djoué-Léfini 三省 + Brazzaville/Pointe-Noire 兩省級直轄市手動補） |

## 待處理（74，另有 1 國暫緩見上方說明）

| 國家 | QID | code | 狀態 |
|---|---|---|---|
| American Samoa | Q16641 | AS | ⬜ |
| Anguilla | Q25228 | AI | ⬜ |
| Antarctica | Q51 | AQ | ⬜ |
| Aruba | Q21203 | AW | ⬜ |
| Ascension | Q31890709 | AC | ⬜ |
| Bermuda | Q23635 | BM | ⬜ |
| Bouvet Island | Q23408 | BV | ⬜ |
| British Indian Ocean Territory | Q43448 | IO | ⬜ |
| British Virgin Islands | Q25305 | VG | ⬜ |
| Caribbean Netherlands | Q27561 | BQ | ⬜ |
| Cayman Islands | Q5785 | KY | ⬜ |
| Christmas Island | Q31063 | CX | ⬜ |
| Clipperton Island | Q161258 | CP | ⬜ |
| Cocos (Keeling) Islands | Q36004 | CC | ⬜ |
| Curaçao | Q25279 | CW | ⬜ |
| Diego Garcia | Q184851 | DG | ⬜ |
| Falkland Islands | Q9648 | FK | ⬜ |
| Faroe Islands | Q4628 | FO | ⬜ |
| French Guiana | Q3769 | GF | ⬜ |
| French Polynesia | Q30971 | PF | ⬜ |
| French Southern and Antarctic Lands | Q129003 | TF | ⬜ |
| German Democratic Republic | Q16957 | DD | ⬜ |
| Gibraltar | Q1410 | GI | ⬜ |
| Greenland | Q223 | GL | ⬜ |
| Guadeloupe | Q17012 | GP | ⬜ |
| Guam | Q16635 | GU | ⬜ |
| Guernsey | Q25230 | GG | ⬜ |
| Heard Island and McDonald Islands | Q131198 | HM | ⬜ |
| Isle of Man | Q9676 | IM | ⬜ |
| Jersey | Q785 | JE | ⬜ |
| Maldives | Q826 | MV | ⚠️ 暫緩，見下方說明 |
| Martinique | Q17054 | MQ | ⬜ |
| Mayotte | Q17063 | YT | ⬜ |
| Montserrat | Q13353 | MS | ⬜ |
| Netherlands Antilles | Q25227 | AN | ⬜ |
| New Caledonia | Q33788 | NC | ⬜ |
| Norfolk Island | Q31057 | NF | ⬜ |
| Northern Mariana Islands | Q16644 | MP | ⬜ |
| Pitcairn Islands | Q35672 | PN | ⬜ |
| Puerto Rico | Q1183 | PR | ⬜ |
| Rwanda | Q1037 | RW | ⬜ |
| Réunion | Q17070 | RE | ⬜ |
| Saint Barthélemy | Q25362 | BL | ⬜ |
| Saint Helena, Ascension and Tristan da Cunha | Q192184 | SH | ⬜ |
| Saint Pierre and Miquelon | Q34617 | PM | ⬜ |
| Saint-Martin | Q126125 | MF | ⬜ |
| Sark | Q3405693 | CQ | ⬜ |
| Senegal | Q1041 | SN | ⬜ |
| Seychelles | Q1042 | SC | ⬜ |
| Sierra Leone | Q1044 | SL | ⬜ |
| Sint Maarten | Q26273 | SX | ⬜ |
| Somalia | Q1045 | SO | ⬜ |
| South Africa | Q258 | ZA | ⬜ |
| South Georgia and the South Sandwich Islands | Q35086 | GS | ⬜ |
| South Sudan | Q958 | SS | ⬜ |
| Sudan | Q1049 | SD | ⬜ |
| Svalbard and Jan Mayen | Q842829 | SJ | ⬜ |
| São Tomé and Príncipe | Q1039 | ST | ⬜ |
| Tanzania | Q924 | TZ | ⬜ |
| The Gambia | Q1005 | GM | ⬜ |
| Togo | Q945 | TG | ⬜ |
| Tokelau | Q36823 | TK | ⬜ |
| Tristan da Cunha | Q34625512 | TA | ⬜ |
| Trust Territory of the Pacific Islands | Q129237 | PC | ⬜ |
| Tunisia | Q948 | TN | ⬜ |
| Turks and Caicos Islands | Q18221 | TC | ⬜ |
| Uganda | Q1036 | UG | ⬜ |
| United States Minor Outlying Islands | Q16645 | UM | ⬜ |
| United States Virgin Islands | Q11703 | VI | ⬜ |
| Wallis and Futuna | Q35555 | WF | ⬜ |
| Western Sahara | Q6250 | EH | ⬜ |
| Yugoslavia | Q36704 | YU | ⬜ |
| Zambia | Q953 | ZM | ⬜ |
| Zimbabwe | Q954 | ZW | ⬜ |
| Åland | Q5689 | AX | ⬜ |
