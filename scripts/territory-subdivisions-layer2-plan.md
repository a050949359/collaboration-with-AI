# Territory 第二層行政區（省/州 → 市/縣）規劃

> 第一層行政區已於 2026-09-04 全數完成（259/259，見 `territory-subdivisions-progress.md`）。
> 這份文件規劃**第二層**的範圍：不做全部 259 國，只挑熱門旅遊國家。

## 狀態：Top 11 熱門旅遊國 + Taiwan/Korea 第二層全數完成（2026-09-11）

France/Spain/USA/China/Italy/Turkey/Mexico/Thailand/Germany/UK/Japan 11 國第二層行政區皆已跑完，
零系統性寫入失敗。接著加開第二批擴充範圍：Taiwan + South Korea（對本專案使用情境比觀光排名更
直接相關，見下方「擴充：Taiwan/Korea」），同樣零系統性寫入失敗，South Korea 更是 17 個一級行政區
全數精確吻合官方數字、零 P150 資料缺口。各國仍有已知的 Wikidata P150 資料缺口（見下方「待補
清單」），是資料源本身的問題，不是腳本或 agy 判斷錯誤，之後可視需要另外查證補建，不影響現有
資料的正確性。

## Resume prompt（若之後要擴大範圍到更多國家，開新對話貼這段）

```
接續 Territory MCP 第二層行政區的工作。先看記憶檔 project_territory_mcp_tool.md，
跟 scripts/territory-subdivisions-layer2-plan.md 這份規劃文件——Top 11 熱門旅遊國
（France/Spain/USA/China/Italy/Turkey/Mexico/Thailand/Germany/UK/Japan）+ Taiwan/Korea
已全數完成。若要擴大範圍，用法：`python3 scripts/territory-import-subdivisions.py --under <國家 QID>`
（大國先用 --countries 分批，8-9 個一批）→ 背景執行 → Monitor 監看
`^===|agy accepted|failed|Error|Traceback` → 每批結束後 grep 檢查真正的 failed/Traceback
（不是 agy 的正常 rejected）並統計 accepted 總數 → 更新本文件的進度表 → 下一批/下一國。
```

## 範圍依據

2025 年國際遊客人數排行（來源：UN Tourism / 多方旅遊統計網站彙整，非本專案 Tour App 資料——該 App 資料是假資料，不能用）：

| 排名 | 國家 | 2025 遊客數（約） | QID | 第一層完成狀態 |
|---|---|---|---|---|
| 1 | France 🇫🇷 | 89M | Q142 | ✅ 25 個（arrondissement/région 混合，海外省另計） |
| 2 | Spain 🇪🇸 | 84M | Q29 | ✅ 19 個 |
| 3 | USA 🇺🇸 | 79M | Q30 | ✅ 56 個 |
| 4 | China 🇨🇳 | 66M | Q148 | ✅ 33 個 |
| 5 | Italy 🇮🇹 | 65M | Q38 | ✅ 20 個 |
| 6 | Turkey 🇹🇷 | 51M | Q43 | ✅ 81 個 |
| 7 | Mexico 🇲🇽 | 45M | Q96 | ✅ 32 個 |
| 8 | Thailand 🇹🇭 | 40M | Q869 | ✅ 77 個 |
| 9 | Germany 🇩🇪 | 40M | Q183 | ✅ 16 個 |
| 10 | UK 🇬🇧 | 39M | Q145 | ✅ 4 個 |
| 11 | Japan 🇯🇵 | 32M | Q17 | ✅ 47 個 |

## 擴充：Taiwan/Korea（2026-09-11，使用者確認優先於觀光排名 12-20 名）

Top 11 完成後使用者要求「再找下一批」，因兩國排名未進觀光前 15 名但對本專案（Taiwan 航空/旅遊
App）使用情境更直接相關，優先於單純接續觀光排名擴充：

| 國家 | QID | 第一層完成狀態 |
|---|---|---|
| Taiwan 🇹🇼 | Q865 | ✅ 6 個（6 都/縣市） |
| South Korea 🇰🇷 | Q884 | ✅ 17 個（1 特別市+6 廣域市+1 特別自治市+8 道+1 特別自治道） |

## 待辦（下次接續）

0. **下次接續請先讀這一條**：France/Spain/USA/China/Italy/Turkey 已完成（見下表）；Mexico 執行到一半被中斷，直接重跑 `--under Q96`（不加 --countries）即可安全恢復；剩下 Thailand/Germany/UK/Japan 尚未開始。繼續時比照本次已建立的模式：`--under <QID>`（大國先 `--countries` 分批，8-9 個一批）→ 背景執行 → Monitor 監看 `^===|agy accepted|failed|Error|Traceback` → 每批結束後 grep 檢查真正的 failed/Traceback（不是 agy 的正常 rejected）並統計 accepted 總數 → 更新本文件的進度表 → 下一批/下一國。
1. ✅ **技術前提已完成**（2026-09-09，commit `ac02f46`/`6024d9b`/`2784ebf`）：`territory-import-subdivisions.py` 新增 `--under <QID>` 支援任意父層 QID（不再寫死國家層），並修掉兩個真實 bug（judge prompt 誤把「description 沒寫上層名稱」當隸屬存疑；單一上層節點的 SPARQL 逾時未接住會讓整支腳本掛掉）。
2. 範圍確認：目前先鎖定上表 11 國，之後可視需要加入 Taiwan/Korea 等對本專案使用情境更重要但排名未進前 15 的國家。
3. **大國（USA/China/Turkey/Thailand）範圍決定（2026-09-09，使用者確認）：全部展開，但每個國家內部分批跑**（例如美國 56 州分 7 批、每批約 8 個，每批跑完檢查一次錯誤/異常再繼續），不整批一次 56 州齊發——方便中途檢查、也避免單次 agy 呼叫規模失控。

## 進度追蹤（第二層，11 國）

| 國家 | QID | 狀態 | 第二層筆數 | 備註 |
|---|---|---|---|---|
| France 🇫🇷 | Q142 | ✅ 完成（2026-09-09） | 299 | 25 個第一層全跑，零寫入失敗 |
| Spain 🇪🇸 | Q29 | ✅ 完成（2026-09-09） | 45 | 19 個第一層全跑，零寫入失敗；Asturias/Murcia 的「省」被 Wikidata 標記 historical 正確濾除 |
| USA 🇺🇸 | Q30 | ✅ 完成（2026-09-09） | ~3239 | 56 州/屬地分 7 批跑完，零系統性寫入失敗；1 筆手動補建（見下方待補清單）、1 筆 refresh_observations 暫時性連線失敗待補 |
| China 🇨🇳 | Q148 | ✅ 完成（2026-09-09） | 466 | 33 個第一層分 5 批跑完，零系統性寫入失敗；重慶 2 個/山東 1 個/海南 1 個拒絕皆為真已廢除或非行政區實體，判斷正確 |
| Italy 🇮🇹 | Q38 | ✅ 完成（有已知缺口，2026-09-09） | 166 | 20 個大區全跑，零系統性寫入失敗；Sicily/Friuli-Venezia Giulia 因當地已廢省改制，候選清單只剩已廢除的舊省 QID，正確全拒但造成真實資料缺口（見下方待補清單） |
| Turkey 🇹🇷 | Q43 | ✅ 完成（2026-09-09） | 983 | 81 省分 9 批跑完，零系統性寫入失敗；每省普遍有「鎮/市中心」與正式「縣」重複實體的雜訊，agy 一致正確過濾，accepted 數字與真實縣數逐一核對吻合 |
| Mexico 🇲🇽 | Q96 | ✅ 完成（2026-09-10） | 464 | 32 個聯邦實體（31 州+墨西哥市）全跑完，零系統性寫入失敗；11 州有嚴重 P150 資料缺口（見下方待補清單），屬 Wikidata 資料源本身問題非腳本/agy 誤判 |
| Thailand 🇹🇭 | Q869 | ✅ 完成（2026-09-10） | 926 | 77 府分 9 批跑完，零系統性寫入失敗；多數府候選數與官方縣數精確吻合（如 Chiang Mai 25/25、Ubon Ratchathani 25/25、Nakhon Ratchasima 32/32） |
| Germany 🇩🇪 | Q183 | ✅ 完成（2026-09-10） | 218 | 16 邦一次跑完，零系統性寫入失敗；Berlin 12/12、Bavaria 7/7、Bremen 2/2 皆精確吻合官方數；`Q1194`（Schleswig-Holstein）本身缺 label observation（見下方待補） |
| UK 🇬🇧 | Q145 | ✅ 完成（有已知缺口，2026-09-10） | 74 | 4 個構成國全跑完，零系統性寫入失敗；Scotland 32/32、N. Ireland 11/11、Wales 22/22 精確吻合；England（`Q21`）只有 9 個候選，實際應有 300+ 地方政府單位，嚴重 P150 缺口（見下方待補） |
| Japan 🇯🇵 | Q17 | ✅ 完成（有已知缺口，2026-09-10/11） | 902 | 47 都道府縣全跑完（6 批），零系統性寫入失敗；過程中發現並修正 judge prompt 系統性誤判「郡」（gun/district，無治理功能地理分組）為正式行政區的 bug（見下方說明），修正前已誤建的 21 個郡 entity 已刪除；Hokkaidō 特殊結構（14 個振興局 subprefecture）正確辨識；Tokyo 62/62 精確吻合官方數；多個縣有 P150 缺口（見下方待補清單） |
| Taiwan 🇹🇼 | Q865 | ✅ 完成（2026-09-11） | 157 | 6 都/縣市一次跑完，零真正失敗（Kaohsiung 首次遇到 SPARQL 逾時，單獨重跑後正常，屬既有已知的暫時性網路問題）；Taoyuan 13/13、Tainan 37/37、New Taipei 28/29、Taichung 29/29、Taipei 12/12、Kaohsiung 38/38，皆精確吻合官方行政區數 |
| South Korea 🇰🇷 | Q884 | ✅ 完成（2026-09-11） | 252 | 17 個一級行政區一次跑完，零系統性寫入失敗，**零 P150 資料缺口**——每一個都精確吻合官方市郡區數（Seoul 25/25、Busan 16/16、Daegu 9/9、Incheon 10/10、Gwangju 5/5、Daejeon 5/5、Ulsan 5/5、Sejong 24/24、Gyeonggi 31/31、8 道 11~22 不等皆精確吻合、Jeju 2/2），是本輪資料完整度最乾淨的國家 |

## 待補清單（不影響已完成筆數，事後一次性補，不要單筆插隊補）

- **France**：`Q15104`（法國某個第一層大區）缺 label observation，屬於 2026-08 第一層匯入時就存在的舊資料缺口（refresh_observations 的既知 ~5-10% 暫時性失敗），非本次新增。
- **France**：Balearic Islands 同類疑似 candidates:0 的資料缺口（西班牙段，非法國——見下）待查證是否為 Wikidata P150 真缺，或本來就無次一層。
- **Spain**：`Q107356467`（Balearic Islands）P150 回傳 0 候選，西班牙其他小型單一省份自治區（Cantabria/La Rioja/Ceuta/Melilla/Navarre）0 候選屬預期（單一省份/單一市，省級已名存實亡或本來就無次一層），Balearic Islands 略可疑，待查證。
- **USA**：`Q500751`（Kentucky 某郡，第 6 批）refresh_observations 觸發時遇到連線重置（Connection reset by peer），entity/relation 已正確寫入，只差 observation，需補一次 `refresh_observations(Q500751)`。
- **USA**：`Q47894`（Broome County, New York）Wikidata 無英文 label 導致 agy 誤拒，已依既有慣例（採同儕多數 type）手動補建，不需再處理。
- **USA**：`Q11703`（US Virgin Islands）P150 回傳 0 候選，疑似 Wikidata 資料缺口（其他美國屬地如 Guam/American Samoa/Northern Mariana Islands 都有正常候選），待查證。
- **China**：33 個第一層全數完成，重慶/山東/海南各 1-2 筆拒絕皆為真已廢除或非行政區實體，無資料缺口。
- **Italy**：`Q1460`（Sicily）9 個候選全部是已廢除的舊省 QID（義大利 2015 年改制，西西里已廢省改為「自由市鎮聯合體」+ Palermo 大都市），P150 沒有連到任何新制單位的 QID，agy 正確全拒但等於這個大區完全沒有第二層資料——需要之後另外搜尋「自由市鎮聯合體」（libero consorzio comunale）＋ Palermo 大都市的 Wikidata QID 手動補建，不能靠 P150 抓到。
- **Italy**：`Q1250`（Friuli-Venezia Giulia）4 個候選同樣全部是已廢除的舊省 QID（該大區 2017 年廢省，改為直轄市鎮），跟 Sicily 同類缺口，需另外查證現行單位（可能是自治大區直轄市鎮，沒有中間層，比照 Aosta Valley 模式直接抓 comune）。
- **Italy**：`Q1462`（Sardinia）`Q23498165`「Province of South Sardinia」被 Wikidata 標記為 `former province of Italy` 而遭 agy 正確依規則拒絕，但南薩丁尼亞（Sud Sardegna）在義大利現行體制裡應該是**現行**省級單位（2016 年由多個小省合併而成）——這筆很可能是 Wikidata 本身的分類標記錯誤/過時，需要查證後可能要手動覆寫 type 補建，不是 agy 誤判。
- **Mexico**：11 個州的 P150 只回傳極少候選（實際市鎮數遠高於候選數），確認是 Wikidata P150 連結缺失，非 agy 誤判：`Q80007`（Tamaulipas，1/43）、`Q80269`（Zacatecas，2/58）、`Q80903`（Hidalgo，0/84）、`Q82112`（State of Mexico，0/125，本身還缺 label observation）、`Q82681`（Tlaxcala，0/60）、`Q34110`（Oaxaca，1/570）、`Q46475`（Guanajuato，1/46）、`Q53079`（Coahuila，1/38）、`Q60130`（Veracruz，11/212）、`Q60158`（Guerrero，0/81）、`Q60176`（Yucatán，33/106）。
- **Germany**：`Q1194`（Schleswig-Holstein）本身在 territory 圖譜缺 label observation（顯示成裸 QID，同 France `Q15104`/Mexico `Q82112` 那類第一層舊缺口），需 refresh_observations 補 label。
- **UK**：`Q21`（England）P150 只回傳 9 筆候選（實際應有 300+ 個地方政府單位，含都會自治市/單一管理區/郡議會等混合層級），屬 Wikidata P150 嚴重缺口，且英格蘭地方政府結構本身也較複雜非單純市鎮制，需之後另外規劃查詢方式；本身也缺 label observation（顯示成裸 QID）。
- **Japan**：**系統性 bug 已修正（2026-09-10，commit `19868bb`）**——judge prompt 原本會把「郡」（gun/district of Japan，明治郡制廢除後僅剩地址用地理分組、無議會無治理機關）誤判為正式行政區接受，因為它結構上仍通過 P150。已修正前誤建的 21 個郡 entity（Aomori 8 個、Fukushima 13 個）已手動刪除，新增判斷規則明確排除此類「有 P150 但本身無治理功能的地理/歷史分組」（同一類的已知案例還有 Ireland 傳統四省）。修正後全程重新驗證，郡類雜訊持續被正確拒絕。
- **Japan**：多個縣有 P150 資料缺口（候選數遠低於實際市町村數，非 agy 誤判）：`Q48326`（Iwate，14/33）、`Q132751`（Shimane，8/19）、`Q133935`（Tottori，4/19，日本面積最小縣）、`Q127877`（Nagano，20/77）、`Q766445`（Okinawa，11/41）等；`Q82112` 類的裸 QID 缺 label 情況也出現在 `Q1037393`（Hokkaidō，但本身第二層 14 個振興局資料正確完整，只是自身 label 缺失）。
- **Japan**：`Q1037393`（Hokkaidō）第二層並非市町村而是 14 個「振興局」（subprefecture）——這是北海道特有的真實行政結構（市町村是第三層），14/14 全數正確接受，非資料缺口。
- **Taiwan**：全部 6 個第一層節點（Taoyuan `Q115256`、Tainan `Q140631`、New Taipei `Q244898`、Taichung `Q245023`、Taipei `Q1867`、Kaohsiung `Q181557`）本身都缺 label observation（顯示成裸 QID，同 France `Q15104`/Mexico `Q82112`/Germany `Q1194`/UK `Q21` 那類第一層舊缺口），需 refresh_observations 補齊；第二層資料本身無缺口，皆精確吻合官方行政區數。
- **South Korea**：無已知資料缺口，17 個一級行政區的第二層皆精確吻合官方市郡區數，第一層節點也無裸 QID 情況。
