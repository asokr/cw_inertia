@php $isFirstGroup = true; @endphp
<table>
    <thead>
        {{-- Заголовок таблицы --}}
        <tr>
            <th colspan="2" width="30"
                style="font-size: 12px; border: 1px solid #000; text-align: center; background-color: #E6E6FA; word-wrap: true;">
                ПРОДАЖИ
            </th>
            <th colspan="2" width="30"
                style="font-size: 12px; border: 1px solid #000; text-align: center; background-color: #E6E6FA; word-wrap: true;">
                ВОЗВРАТЫ
            </th>
            <th width="15"
                style="font-size: 12px; border: 1px solid #000; text-align: center; background-color: #E6E6FA; word-wrap: true;">
                % ВЫКУПА
            </th>
            <th width="30"
                style="font-size: 12px; border: 1px solid #000; text-align: center; background-color: #E6E6FA; word-wrap: true;">
                ШТРАФЫ И
                ДОПЛАТЫ</th>
            <th width="20"
                style="font-size: 12px; border: 1px solid #000; text-align: center; background-color: #E6E6FA; word-wrap: true;">
                ЛОГИСТИКА</th>
            <th width="30"
                style="font-size: 12px; border: 1px solid #000; text-align: center; background-color: #E6E6FA; word-wrap: true;">
                СЕБЕСТОИМОСТЬ</th>
            <th width="20"
                style="font-size: 12px; border: 1px solid #000; text-align: center; background-color: #E6E6FA; word-wrap: true;">
                ИТОГ
            </th>
            <th width="20"
                style="font-size: 12px; border: 1px solid #000; text-align: center; background-color: #E6E6FA; word-wrap: true;">
                МАРЖА
            </th>
            <th width="30" colspan="2"
                style="font-size: 12px; border: 1px solid #000; text-align: center; background-color: #E6E6FA; word-wrap: true;">
                РЕНТАБЕЛЬНОСТЬ (%)</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td width="20" style="border: 1px solid #000; word-wrap: true; text-align: right;">
                {{ $reportData['sales_amount'] }}</td>
            <td width="10" style="border: 1px solid #000; word-wrap: true; text-align: center;">
                {{ $reportData['sales_quantity'] }}</td>
            <td width="20" style="border: 1px solid #000; word-wrap: true; text-align: right;">
                {{ $reportData['returns_amount'] }}</td>
            <td width="10" style="border: 1px solid #000; word-wrap: true; text-align: center;">
                {{ $reportData['returns_quantity'] }}</td>
            <td style="border: 1px solid #000; word-wrap: true; text-align: center;">
                {{ $reportData['percent_buy'] }}</td>
            <td style="border: 1px solid #000; word-wrap: true; text-align: right;">
                {{ $reportData['penalties'] + $reportData['storage_fee'] + $reportData['deduction'] + ($reportData['cashback'] ?? 0) + ($reportData['dop_rashod'] ?? 0) + ($reportData['nalog'] ?? 0) }}
            </td>
            <td style="border: 1px solid #000; word-wrap: true; text-align: right;">
                {{ $reportData['logistics'] }}</td>
            <td style="border: 1px solid #000; word-wrap: true; text-align: right;">
                {{ $reportData['purchase_cost'] }}</td>
            <td style="border: 1px solid #000; word-wrap: true; text-align: right;">
                {{ $reportData['itog'] }}</td>
            <td style="border: 1px solid #000; word-wrap: true; text-align: right;">
                {{ $reportData['margin'] }}</td>
            <td colspan="2" style="border: 1px solid #000; word-wrap: true; text-align: right;">
                {{ $reportData['total_profitability'] }}</td>
        </tr>
        @foreach ($items as $group)
            @if ($group['supplier_oper_name'] == 'Продажа')
                {{-- Заголовки детализированной таблицы --}}
                <tr>
                    <td colspan="11"></td>
                </tr>
                <tr>
                    <th colspan="4" width="20"
                        style="font-size: 12px; border: 1px solid #000; text-align: left; background-color: #E6E6FA; word-wrap: true;">
                        Продажи:
                    </th>
                </tr>
                <tr>
                    <th width="40" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">Товар
                    </th>
                    <th width="12" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Размер</th>
                    <th width="20" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Штрихкод</th>
                    <th width="25" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">Склад
                    </th>
                    <th width="12" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Кол-во</th>
                    <th width="20" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">Сумма
                        к
                        перечислению</th>
                    <th width="20" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Закупочная
                        цена</th>
                    <th width="15" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Логистика
                    </th>
                    <th width="15" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">Итог
                    </th>
                    <th width="20" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Затраты/
                        доплаты</th>
                    <th width="15" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Кешбэк
                    </th>
                    <th width="15" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Доп.расход
                    </th>
                    <th width="15" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Налог
                    </th>
                    <th width="15" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">Маржа
                    </th>
                    <th width="20" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Рентабельность (%)</th>
                </tr>
                @foreach ($group['items'] as $item)
                    <tr>
                        <td width="40" style="border: 1px solid #000; word-wrap: true;">{{ $item['sa_name'] }}</td>
                        <td width="12" style="border: 1px solid #000; word-wrap: true; text-align: center;">
                            {{ $item['size'] }}</td>
                        <td width="20" style="border: 1px solid #000; word-wrap: true;">{{ $item['barcode'] }}</td>
                        <td width="25" style="border: 1px solid #000; word-wrap: true;">{{ $item['warehouse'] }}
                        </td>
                        <td width="12" style="border: 1px solid #000; word-wrap: true; text-align: center;">
                            {{ $item['quantity'] }}</td>
                        <td width="20" style="border: 1px solid #000; word-wrap: true; text-align: right;">
                            {{ $item['sum_to_transfer'] }}</td>
                        <td width="20" style="border: 1px solid #000; word-wrap: true; text-align: right;">
                            {{ $item['purchase_cost'] }}</td>
                        <td width="15" style="border: 1px solid #000; word-wrap: true; text-align: right;">
                            {{ $item['logistics'] }}</td>
                        <td width="15" style="border: 1px solid #000; word-wrap: true; text-align: right;">
                            {{ $item['item_total'] }}</td>
                        <td width="20" style="border: 1px solid #000; word-wrap: true; text-align: right;">
                            {{ $item['cost_adjustments'] }}</td>
                        <td width="15" style="border: 1px solid #000; word-wrap: true; text-align: right;">
                            {{ $item['cashback'] ?? 0 }}</td>
                        <td width="15" style="border: 1px solid #000; word-wrap: true; text-align: right;">
                            {{ $item['dop_rashod'] ?? 0 }}</td>
                        <td width="15" style="border: 1px solid #000; word-wrap: true; text-align: right;">
                            {{ $item['nalog'] ?? 0 }}</td>
                        <td width="15" style="border: 1px solid #000; word-wrap: true; text-align: right;">
                            {{ $item['margin'] }}</td>
                        <td width="20" style="border: 1px solid #000; word-wrap: true; text-align: right;">
                            {{ $item['profitability_percent'] }}</td>
                    </tr>
                @endforeach
            @elseif ($group['supplier_oper_name'] == 'Возврат')
                <tr>
                    <td colspan="20"></td>
                </tr>
                <tr>
                    <td colspan="20"></td>
                </tr>
                <tr>
                    <th colspan="4" width="20"
                        style="font-size: 12px; border: 1px solid #000; text-align: left; background-color: #E6E6FA; word-wrap: true;">
                        Возвраты:
                    </th>
                </tr>
                <tr>
                    <th width="40" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Товар
                    </th>
                    <th width="12" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Размер</th>
                    <th width="20" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Штрихкод</th>
                    <th width="25" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Склад
                    </th>
                    <th width="12" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Кол-во</th>
                    <th width="20" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Сумма </th>
                    <th width="20" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Закупочная
                        цена</th>

                    <th width="15" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Итог
                    </th>

                </tr>
                @foreach ($group['items'] as $item)
                    <tr>
                        <td width="40" style="border: 1px solid #000; word-wrap: true;">{{ $item['sa_name'] }}
                        </td>
                        <td width="12" style="border: 1px solid #000; word-wrap: true; text-align: center;">
                            {{ $item['size'] }}</td>
                        <td width="20" style="border: 1px solid #000; word-wrap: true;">{{ $item['barcode'] }}
                        </td>
                        <td width="25" style="border: 1px solid #000; word-wrap: true;">{{ $item['warehouse'] }}
                        </td>
                        <td width="12" style="border: 1px solid #000; word-wrap: true; text-align: center;">
                            {{ $item['quantity'] }}</td>
                        <td width="20" style="border: 1px solid #000; word-wrap: true; text-align: right;">
                            {{ $item['sum_to_transfer'] }}</td>
                        <td width="20" style="border: 1px solid #000; word-wrap: true; text-align: right;">
                            {{ $item['purchase_cost'] }}</td>
                        <td width="15" style="border: 1px solid #000; word-wrap: true; text-align: right;">
                            {{ $item['item_total'] }}</td>
                    </tr>
                @endforeach
            @elseif ($group['supplier_oper_name'] == 'Логистика')
                <tr>
                    <td colspan="20"></td>
                </tr>
                <tr>
                    <td colspan="20"></td>
                </tr>
                <tr>
                    <th colspan="4" width="20"
                        style="font-size: 12px; border: 1px solid #000; text-align: left; background-color: #E6E6FA; word-wrap: true;">
                        Логистика:
                    </th>
                </tr>
                <tr>
                    <th width="40" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Товар
                    </th>
                    <th width="12" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Размер</th>
                    <th width="20" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Штрихкод</th>
                    <th width="25" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Склад
                    </th>
                    <th colspan="2" style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Обоснование </th>

                    <th style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                        Стоимость
                    </th>

                </tr>
                @foreach ($group['items'] as $item)
                    <tr>
                        <td width="40" style="border: 1px solid #000; word-wrap: true;">{{ $item['sa_name'] }}
                        </td>
                        <td width="12" style="border: 1px solid #000; word-wrap: true; text-align: center;">
                            {{ $item['size'] }}</td>
                        <td width="20" style="border: 1px solid #000; word-wrap: true;">{{ $item['barcode'] }}
                        </td>
                        <td width="25" style="border: 1px solid #000; word-wrap: true;">{{ $item['warehouse'] }}
                        </td>
                        <td colspan="2" style="border: 1px solid #000; word-wrap: true; text-align: center;">
                            {{ $item['reasoning'] }}</td>
                        <td width="20" style="border: 1px solid #000; word-wrap: true; text-align: right;">
                            {{ $item['logistics'] }}</td>
                    </tr>
                @endforeach
            @else
                @if ($isFirstGroup)
                    @php $isFirstGroup = false; @endphp
                    <tr>
                        <td colspan="20"></td>
                    </tr>
                    <tr>
                        <td colspan="20"></td>
                    </tr>
                    <tr>
                        <th colspan="4" width="20"
                            style="font-size: 12px; border: 1px solid #000; text-align: left; background-color: #E6E6FA; word-wrap: true;">
                            Удержания и доплаты:
                        </th>
                    </tr>
                    <tr>
                        <th style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                            Тип затраты
                        </th>
                        <th colspan="2"
                            style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                            Обоснование</th>
                        <th colspan="2"
                            style="border: 1px solid #000; word-wrap: true; background-color: #E6E6FA;">
                            Стоимость</th>
                    </tr>
                @endif
                @foreach ($group['items'] as $item)
                    <tr>
                        <td style="border: 1px solid #000; word-wrap: true;">
                            {{ $group['supplier_oper_name'] }}
                        </td>
                        <td colspan="2" style="border: 1px solid #000; word-wrap: true; text-align: center;">
                            {{ $item['reasoning'] }}</td>
                        <td colspan="2" style="border: 1px solid #000; word-wrap: true;">
                            {{ $item['sum_to_transfer'] }}
                        </td>
                    </tr>
                @endforeach
            @endif
        @endforeach
    </tbody>
</table>
