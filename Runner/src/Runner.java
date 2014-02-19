import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.util.ArrayList;

import com.google.common.geometry.*;

/**
 * Created with IntelliJ IDEA.
 * User: Romanko
 * Date: 21/08/13
 * Time: 6:26 PM
 */
public class Runner
{
    public static void collectCells(String[] input)
    {
        if (input.length != 4) {
            throw new IllegalArgumentException("\"cells\" command expects two input parameters, " + Integer.toString(input.length) + " given");
        }

        S2RegionCoverer coverer = new S2RegionCoverer();
        coverer.setMinLevel(16);
        coverer.setMaxLevel(16);

        /**
         * input[0] SW Lat
         * input[1] SW Lng
         * input[2] NE Lat
         * input[3] NE Lng
         */

        Double[] swCoord = { new Double(input[0]), new Double(input[1]) };
        Double[] neCoord = { new Double(input[2]), new Double(input[3]) };

        S2LatLngRect rect = S2LatLngRect.fromPointPair( S2LatLng.fromDegrees(swCoord[0], swCoord[1]), S2LatLng.fromDegrees(neCoord[0], neCoord[1]) );

        ArrayList<S2CellId> cells = new ArrayList<>();
        coverer.getCovering(rect, cells);

        for (S2CellId cell : cells) {
            System.out.println(Long.toHexString(cell.id()));
        }
    }

    public static void parseEnergyGlob(String[] input)
    {
        if (input.length != 1) {
            throw new IllegalArgumentException("\"glob\" command expects two input parameters, " + Integer.toString(input.length) + " given");
        }

        String guid = input[0];

        S2LatLng coord = new S2CellId( Long.parseLong(guid.substring(0, 16), 16) ).toLatLng();
        Double lat = coord.lat().degrees();
        Double lng = coord.lng().degrees();

        String energyHex = guid.substring( guid.length() - 4, guid.length() - 2 );
        Integer amount = Integer.parseInt(energyHex, 16);

        System.out.println(lat);
        System.out.println(lng);
        System.out.println(amount);
    }

    public static void main(String args[]) throws Exception
    {
        BufferedReader bufReader = new BufferedReader( new InputStreamReader(System.in) );
        String line;

        while(( line = bufReader.readLine() ) != null && !line.startsWith("exit")) {
            if ( !line.contains(" ") ) {
                throw new IllegalArgumentException("Parameters must be separated by spaces");
            }

            String[] tmp = line.trim().split("\\s+");
            String[] params = new String[tmp.length - 1];
            String cmd = tmp[0];

            System.arraycopy(tmp, 1, params, 0, tmp.length - 1);

            switch ( cmd.toLowerCase() ) {
                case "cells":
                    collectCells(params);
                    break;

                case "glob":
                    parseEnergyGlob(params);
                    break;

                default:
                    throw new IllegalArgumentException("Unknown command: " + cmd);
            }

            System.out.println(".");
        }
    }
}
