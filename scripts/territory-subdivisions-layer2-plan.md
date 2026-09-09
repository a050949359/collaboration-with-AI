# Territory 第二層行政區（省/州 → 市/縣）規劃

> 第一層行政區已於 2026-09-04 全數完成（259/259，見 `territory-subdivisions-progress.md`）。
> 這份文件規劃**第二層**的範圍：不做全部 259 國，只挑熱門旅遊國家。

## Resume prompt（下次開新對話直接貼這段）

```
接續 Territory MCP 第二層行政區的工作。先看記憶檔 project_territory_mcp_tool.md
最上面「2026-09-04: PROJECT COMPLETE」那段，跟 scripts/territory-subdivisions-layer2-plan.md
這份規劃文件。第一步：把 territory-import-subdivisions.py 改成支援任意父層 QID
（目前寫死 parent = 國家層的 countries 表資料），改完之後從 Top 11 熱門旅遊國
（France/Spain/USA/China/Italy/Turkey/Mexico/Thailand/Germany/UK/Japan）開始跑第二層。
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
| Mexico 🇲🇽 | Q96 | ⚠️ 中斷，未完成（2026-09-09） | 不明 | 使用者需要關機，執行到 Nayarit（Q79920）附近時中斷；log 因程序被 kill 未 flush 到檔案，實際已寫入到哪個州不明，但 create_entity/create_relation/refresh_observations 皆冪等，**下次直接重新執行 `python3 scripts/territory-import-subdivisions.py --under Q96`（不加 --countries）即可安全恢復**，已寫入的州會被冪等地重新確認，不會產生重複資料 |
| Thailand 🇹🇭 | Q869 | ⬜ 待處理 | — | |
| Germany 🇩🇪 | Q183 | ⬜ 待處理 | — | |
| UK 🇬🇧 | Q145 | ⬜ 待處理 | — | |
| Japan 🇯🇵 | Q17 | ⬜ 待處理 | — | |

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
